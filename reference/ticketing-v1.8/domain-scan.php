<?php
declare(strict_types=1);

function domain_scan_ensure_schema(): void
{
    // ۱.۳۷.۵ — ستون assets.source در نصب‌های قدیمی مقدار 'domain' را نمی‌پذیرد؛
    // در MySQL سخت‌گیرانه این باعث خطای «Data truncated» و ساخته‌نشدن رکورد کامپیوتر
    // دامنه می‌شد و در حالت معمولی هم مقدار ستون خالی می‌ماند. یک‌بار برای همیشه گسترده می‌شود.
    static $sourceEnumFixed = false;
    if (!$sourceEnumFixed) {
        $sourceEnumFixed = true;
        try {
            $type = (string) db()->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets' AND COLUMN_NAME = 'source'")->fetchColumn();
            if ($type !== '' && stripos($type, 'domain') === false) {
                db()->exec("ALTER TABLE assets MODIFY source ENUM('manual','server_inventory','agent','domain') NOT NULL DEFAULT 'manual'");
                if (function_exists('system_log')) {
                    system_log('info', 'domain_scan', 'ستون assets.source برای مقدار domain گسترده شد');
                }
            }
        } catch (Throwable $exception) {
            error_log('[domain-scan] widen assets.source failed: ' . $exception->getMessage());
        }
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec('CREATE TABLE IF NOT EXISTS domain_scan_runs (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, started_by INT UNSIGNED NULL, status VARCHAR(20) NOT NULL DEFAULT "running", total INT NOT NULL DEFAULT 0, done INT NOT NULL DEFAULT 0, online INT NOT NULL DEFAULT 0, offline INT NOT NULL DEFAULT 0, failed INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    db()->exec('CREATE TABLE IF NOT EXISTS domain_scan_queue (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, run_id INT UNSIGNED NOT NULL, asset_id INT UNSIGNED NULL, hostname VARCHAR(190) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT "pending", online TINYINT(1) NOT NULL DEFAULT 0, message VARCHAR(255) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY run_status (run_id, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function domain_scan_timeout_ms(): int
{
    $value = (int) setting('domain_scan_timeout', '2500');
    return $value >= 500 && $value <= 30000 ? $value : 2500;
}

function domain_scan_account_label(): string
{
    $user = trim((string) setting('domain_scan_username', ''));
    $domain = trim((string) setting('domain_scan_domain', ''));
    if ($user === '') {
        return 'حساب سرویس ویندوز پنل (بدون رمز ذخیره‌شده)';
    }
    if (strpos($user, '\\') !== false || strpos($user, '@') !== false) {
        return $user;
    }
    return ($domain !== '' ? $domain . '\\' : '') . $user;
}

function domain_scan_ad_time(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || !ctype_digit($value)) {
        return null;
    }
    $filetime = (float) $value;
    if ($filetime <= 0.0) {
        return null;
    }
    $unix = ($filetime / 10000000.0) - 11644473600.0;
    if ($unix <= 0.0 || $unix > 4102444800.0) {
        return null;
    }
    return date('Y-m-d H:i:s', (int) $unix);
}

function ldap_domain_computers(int $limit = 3000): array
{
    if (!function_exists('ldap_connect')) {
        throw new RuntimeException('افزونهٔ LDAP در PHP فعال نیست.');
    }
    $host = trim((string) ldap_cfg('host', ''));
    $baseDn = trim((string) ldap_cfg('base_dn', ''));
    if ($host === '' || $baseDn === '') {
        throw new RuntimeException('آدرس کنترلر و Base DN دامین را در تنظیمات وارد کنید.');
    }
    $ssl = (bool) ldap_cfg('ssl', false);
    $connection = @ldap_connect(($ssl ? 'ldaps://' : 'ldap://') . $host, (int) ldap_cfg('port', $ssl ? 636 : 389));
    if (!$connection) {
        throw new RuntimeException('اتصال به کنترلر دامین برقرار نشد.');
    }
    ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
    if (defined('LDAP_OPT_NETWORK_TIMEOUT')) { @ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, 10); }
    if (defined('LDAP_OPT_TIMELIMIT')) { @ldap_set_option($connection, LDAP_OPT_TIMELIMIT, 10); }
    $bindDn = (string) ldap_cfg('bind_dn', '');
    if ($bindDn !== '' && !@ldap_bind($connection, $bindDn, (string) ldap_cfg('bind_password', ''))) {
        throw new RuntimeException('ورود حساب سرویس LDAP ناموفق بود.');
    }
    $filter = '(&(objectCategory=computer)(objectClass=computer))';
    $attributes = ['dNSHostName', 'name', 'operatingSystem', 'operatingSystemVersion', 'distinguishedName', 'description', 'lastLogonTimestamp', 'userAccountControl'];
    $computers = [];
    $cookie = '';
    do {
        if (function_exists('ldap_control_paged_result')) {
            @ldap_control_paged_result($connection, 500, false, $cookie);
        }
        $search = @ldap_search($connection, $baseDn, $filter, $attributes);
        if (!$search) {
            throw new RuntimeException('جست‌وجوی کامپیوترهای دامین ناموفق بود.');
        }
        $entries = ldap_get_entries($connection, $search);
        for ($index = 0; $index < (int) ($entries['count'] ?? 0); $index++) {
            $entry = $entries[$index];
            $hostname = trim((string) ($entry['dnshostname'][0] ?? ''));
            if ($hostname === '') {
                $hostname = trim((string) ($entry['name'][0] ?? ''));
            }
            if ($hostname === '') {
                continue;
            }
            $computers[] = [
                'hostname' => $hostname,
                'name' => trim((string) ($entry['name'][0] ?? $hostname)),
                'dn' => (string) ($entry['distinguishedname'][0] ?? ''),
                'os' => (string) ($entry['operatingsystem'][0] ?? ''),
                'os_version' => (string) ($entry['operatingsystemversion'][0] ?? ''),
                'description' => (string) ($entry['description'][0] ?? ''),
                'last_logon' => domain_scan_ad_time((string) ($entry['lastlogontimestamp'][0] ?? '')),
                'enabled' => (((int) ($entry['useraccountcontrol'][0] ?? 0)) & 2) !== 2,
            ];
            if (count($computers) >= $limit) {
                break 2;
            }
        }
        if (function_exists('ldap_control_paged_result_response')) {
            $estimated = 0;
            @ldap_control_paged_result_response($connection, $search, $cookie, $estimated);
        } else {
            $cookie = '';
        }
    } while ($cookie !== '');
    usort($computers, static fn (array $a, array $b): int => strcasecmp($a['hostname'], $b['hostname']));
    return $computers;
}

function domain_scan_asset_for(string $hostname): int
{
    $hostname = trim($hostname);
    if ($hostname === '') {
        return 0;
    }
    $query = db()->prepare('SELECT id FROM assets WHERE LOWER(hostname) = LOWER(?) ORDER BY id ASC LIMIT 1');
    $query->execute([$hostname]);
    $assetId = (int) ($query->fetchColumn() ?: 0);
    if ($assetId > 0) {
        return $assetId;
    }
    $short = strtoupper(explode('.', $hostname)[0]);
    $tag = substr('DOMAIN-' . (string) preg_replace('/[^A-Za-z0-9_-]/', '-', $short), 0, 90);
    $exists = db()->prepare('SELECT id FROM assets WHERE asset_tag = ? LIMIT 1');
    $exists->execute([$tag]);
    $assetId = (int) ($exists->fetchColumn() ?: 0);
    if ($assetId > 0) {
        db()->prepare('UPDATE assets SET hostname = ?, source = "domain", updated_at = NOW() WHERE id = ?')->execute([$hostname, $assetId]);
        return $assetId;
    }
    db()->prepare('INSERT INTO assets (asset_tag, hostname, computer_type, source) VALUES (?, ?, "", "domain")')->execute([$tag, $hostname]);
    return (int) db()->lastInsertId();
}

function domain_scan_ad_values(array $pc): array
{
    return [
        'ad_dn' => (string) ($pc['dn'] ?? ''),
        'ad_os' => (string) ($pc['os'] ?? ''),
        'ad_os_version' => (string) ($pc['os_version'] ?? ''),
        'ad_last_logon' => $pc['last_logon'] ?? null,
        'ad_description' => (string) ($pc['description'] ?? ''),
    ];
}

function domain_scan_start(int $userId): array
{
    domain_scan_ensure_schema();
    $computers = ldap_domain_computers();
    if ($computers === []) {
        throw new RuntimeException('در دامین هیچ کامپیوتری پیدا نشد.');
    }
    db()->prepare('INSERT INTO domain_scan_runs (started_by, status, total) VALUES (?, "running", ?)')->execute([$userId > 0 ? $userId : null, count($computers)]);
    $runId = (int) db()->lastInsertId();
    $insertQueue = db()->prepare('INSERT INTO domain_scan_queue (run_id, asset_id, hostname, status) VALUES (?, ?, ?, "pending")');
    foreach ($computers as $pc) {
        $assetId = domain_scan_asset_for((string) $pc['hostname']);
        if ($assetId > 0) {
            asset_profile_apply_auto($assetId, domain_scan_ad_values($pc), $userId, true);
            if (empty($pc['enabled'])) {
                asset_profile_apply_auto($assetId, ['ad_description' => trim('حساب کامپیوتر در دامنه غیرفعال است. ' . (string) $pc['description'])], $userId, true);
            }
        }
        $insertQueue->execute([$runId, $assetId > 0 ? $assetId : null, (string) $pc['hostname']]);
    }
    system_log('info', 'domain_scan', 'اسکن دامنه آغاز شد', ['run_id' => $runId, 'total' => count($computers)]);
    return ['run_id' => $runId, 'total' => count($computers)];
}

function domain_scan_start_selected(array $assetIds, array $user): array
{
    domain_scan_ensure_schema();
    $ids = array_values(array_unique(array_filter(array_map('intval', $assetIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        throw new RuntimeException('ابتدا سیستم‌هایی را برای استخراج انتخاب کنید.');
    }
    if (count($ids) > 900) {
        throw new RuntimeException('برای هر نوبت حداکثر ۹۰۰ سیستم را انتخاب کنید تا همهٔ انتخاب‌ها به‌درستی ارسال شوند.');
    }
    [$scope, $scopeParams] = asset_scope($user);
    $where = $scope !== '' ? $scope . ' AND ' : 'WHERE ';
    $where .= 'a.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $query = db()->prepare('SELECT a.id, a.hostname, a.ip_address FROM assets a ' . $where . ' ORDER BY a.hostname, a.id');
    $query->execute(array_merge($scopeParams, $ids));
    $assets = [];
    foreach ($query->fetchAll() as $row) {
        $hostname = trim((string) ($row['hostname'] ?? ''));
        if ($hostname === '') {
            $hostname = trim((string) ($row['ip_address'] ?? ''));
        }
        if ($hostname !== '') {
            $assets[(int) $row['id']] = $hostname;
        }
    }
    if ($assets === []) {
        throw new RuntimeException('سیستم انتخاب‌شده‌ای با نام کامپیوتر یا IP معتبر و دسترسی مجاز پیدا نشد.');
    }
    db()->prepare('INSERT INTO domain_scan_runs (started_by, status, total) VALUES (?, "running", ?)')->execute([(int) $user['id'], count($assets)]);
    $runId = (int) db()->lastInsertId();
    $insert = db()->prepare('INSERT INTO domain_scan_queue (run_id, asset_id, hostname, status) VALUES (?, ?, ?, "pending")');
    foreach ($assets as $assetId => $hostname) {
        $insert->execute([$runId, $assetId, $hostname]);
    }
    system_log('info', 'domain_scan', 'استخراج شبکه‌ای سیستم‌های انتخاب‌شده آغاز شد', ['run_id' => $runId, 'total' => count($assets)]);
    return ['run_id' => $runId, 'total' => count($assets)];
}

function domain_scan_import(int $userId): array
{
    domain_scan_ensure_schema();
    if (function_exists('set_time_limit')) { @set_time_limit(300); }
    $computers = ldap_domain_computers();
    if ($computers === []) {
        throw new RuntimeException('در دامین هیچ کامپیوتری پیدا نشد. تنظیمات اتصال دامنه را بررسی کنید.');
    }
    $created = 0;
    $updated = 0;
    foreach ($computers as $pc) {
        $host = trim((string) ($pc['hostname'] ?? ''));
        if ($host === '') {
            continue;
        }
        $exists = db()->prepare('SELECT id FROM assets WHERE LOWER(hostname) = LOWER(?) LIMIT 1');
        $exists->execute([$host]);
        if ($exists->fetchColumn()) {
            $updated++;
        } else {
            $created++;
        }
        $assetId = domain_scan_asset_for($host);
        if ($assetId > 0) {
            asset_profile_apply_auto($assetId, domain_scan_ad_values($pc), $userId, true);
            if (empty($pc['enabled'])) {
                asset_profile_apply_auto($assetId, ['ad_description' => trim('حساب کامپیوتر در دامنه غیرفعال است. ' . (string) $pc['description'])], $userId, true);
            }
        }
    }
    system_log('info', 'domain_scan', 'ورود کامپیوترهای دامنه', ['created' => $created, 'updated' => $updated, 'total' => count($computers)]);
    return ['created' => $created, 'updated' => $updated, 'total' => count($computers)];
}

/** مسیر کامل PowerShell ویندوز (بدون وابستگی به PATH سرویس Apache). */
function domain_scan_powershell_path(): string
{
    $root = (string) (getenv('SystemRoot') ?: getenv('windir') ?: 'C:\\Windows');
    $full = rtrim($root, '\\/') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    return is_file($full) ? $full : 'powershell.exe';
}

/**
 * اجرای هم‌زمان چند پردازش. خروجی هر پردازش در فایل موقت نوشته می‌شود، چون pipe غیرمسدود روی ویندوز
 * قابل‌اعتماد نیست. هر پردازشِ بیش از $timeoutSec ثانیه متوقف می‌شود تا یک سیستم کند کل اسکن را نخواباند.
 *
 * @param array<string,array{cmd:array<int,string>,env?:array<string,string>}> $jobs
 * @return array<string,array{code:int,out:string,err:string,timed_out:bool,started:bool}>
 */
function domain_scan_run_processes(array $jobs, int $concurrency, int $timeoutSec): array
{
    $results = [];
    $running = [];
    $queue = array_keys($jobs);
    $tmpDir = sys_get_temp_dir();
    $concurrency = max(1, $concurrency);
    $timeoutSec = max(1, $timeoutSec);
    $baseEnv = null;
    if (!function_exists('proc_open')) {
        foreach ($queue as $key) {
            $results[$key] = ['code' => -1, 'out' => '', 'err' => 'proc_open is disabled in PHP', 'timed_out' => false, 'started' => false];
        }
        return $results;
    }
    while ($queue !== [] || $running !== []) {
        while ($queue !== [] && count($running) < $concurrency) {
            $key = array_shift($queue);
            $job = $jobs[$key];
            $out = tempnam($tmpDir, 'itsm');
            $err = tempnam($tmpDir, 'itsm');
            if ($out === false || $err === false) {
                $results[$key] = ['code' => -1, 'out' => '', 'err' => 'temp file creation failed', 'timed_out' => false, 'started' => false];
                continue;
            }
            $env = null;
            if (!empty($job['env'])) {
                if ($baseEnv === null) {
                    $baseEnv = getenv();
                }
                $env = array_merge(is_array($baseEnv) ? $baseEnv : [], $job['env']);
            }
            $pipes = [];
            $proc = @proc_open($job['cmd'], [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, null, $env);
            if (!is_resource($proc)) {
                @unlink($out);
                @unlink($err);
                $results[$key] = ['code' => -1, 'out' => '', 'err' => 'proc_open failed', 'timed_out' => false, 'started' => false];
                continue;
            }
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            $running[$key] = ['proc' => $proc, 'out' => $out, 'err' => $err, 'deadline' => microtime(true) + $timeoutSec];
        }
        foreach ($running as $key => $info) {
            $status = proc_get_status($info['proc']);
            $timedOut = false;
            if (!empty($status['running'])) {
                if (microtime(true) <= $info['deadline']) {
                    continue;
                }
                @proc_terminate($info['proc']);
                $timedOut = true;
            }
            $code = $timedOut ? -1 : (int) ($status['exitcode'] ?? -1);
            @proc_close($info['proc']);
            $outText = (string) @file_get_contents($info['out']);
            $errText = (string) @file_get_contents($info['err']);
            @unlink($info['out']);
            @unlink($info['err']);
            $results[$key] = ['code' => $code, 'out' => $outText, 'err' => $errText, 'timed_out' => $timedOut, 'started' => true];
            unset($running[$key]);
        }
        if ($running !== []) {
            usleep(40000);
        }
    }
    return $results;
}

function domain_scan_resolve_ipv4(string $host): string
{
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $host;
    }
    $ip = @gethostbyname($host);
    return ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ? $ip : '';
}

/** @return array<int,string> */
function domain_scan_ping_command(string $target, int $timeoutMs): array
{
    if (PHP_OS_FAMILY === 'Windows') {
        return ['ping', '-4', '-n', '1', '-w', (string) $timeoutMs, $target];
    }
    return ['ping', '-4', '-c', '1', '-W', (string) max(1, (int) ceil($timeoutMs / 1000)), $target];
}

/**
 * پینگ ICMP هم‌زمان؛ کلید خروجی همان کلید ورودی است.
 * @param array<string,string> $targets
 * @return array<string,bool>
 */
function domain_scan_icmp_round(array $targets, int $timeoutMs): array
{
    $jobs = [];
    foreach ($targets as $key => $target) {
        $jobs[$key] = ['cmd' => domain_scan_ping_command($target, $timeoutMs)];
    }
    $results = domain_scan_run_processes($jobs, 48, max(8, (int) ceil($timeoutMs / 1000) + 8));
    $answer = [];
    foreach ($targets as $key => $_target) {
        $row = $results[$key] ?? null;
        $answer[$key] = $row !== null && $row['started'] && !$row['timed_out'] && preg_match('/ttl\s*=/i', $row['out']) === 1;
    }
    return $answer;
}

/**
 * بررسی TCP هم‌زمان (پورت‌های SMB/RPC/RDP). فایروال ویندوز اغلب ICMP را می‌بندد ولی سیستم روشن است.
 * @param array<string,string> $targets کلید => IP
 * @param array<int,int> $ports
 * @return array<string,bool>
 */
function domain_scan_tcp_probe(array $targets, array $ports, int $timeoutMs): array
{
    $up = [];
    $pending = [];
    $seq = 0;
    try {
        foreach ($targets as $key => $ip) {
            foreach ($ports as $port) {
                $errno = 0;
                $error = '';
                $socket = @stream_socket_client('tcp://' . $ip . ':' . $port, $errno, $error, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
                if ($socket) {
                    stream_set_blocking($socket, false);
                    $pending[$seq++] = ['key' => $key, 'socket' => $socket];
                }
            }
        }
        $deadline = microtime(true) + max(0.3, $timeoutMs / 1000);
        while ($pending !== [] && microtime(true) < $deadline) {
            $read = null;
            $write = [];
            $except = [];
            foreach ($pending as $id => $row) {
                if (isset($up[$row['key']])) {
                    fclose($row['socket']);
                    unset($pending[$id]);
                    continue;
                }
                $write[$id] = $row['socket'];
                $except[$id] = $row['socket'];
            }
            if ($write === []) {
                break;
            }
            $left = max(0.0, $deadline - microtime(true));
            $ready = @stream_select($read, $write, $except, 0, (int) min(200000, $left * 1000000));
            if ($ready === false) {
                break;
            }
            foreach ($except as $id => $_socket) {
                if (isset($pending[$id])) {
                    fclose($pending[$id]['socket']);
                    unset($pending[$id]);
                }
            }
            foreach ($write as $id => $socket) {
                if (!isset($pending[$id])) {
                    continue;
                }
                $name = @stream_socket_get_name($socket, true);
                if ($name !== false && $name !== '') {
                    $up[$pending[$id]['key']] = true;
                }
                fclose($socket);
                unset($pending[$id]);
            }
        }
    } catch (Throwable $exception) {
        error_log('[domain-scan] tcp probe: ' . $exception->getMessage());
    }
    foreach ($pending as $row) {
        if (is_resource($row['socket'])) {
            @fclose($row['socket']);
        }
    }
    return $up;
}

/**
 * پینگ هم‌زمان چند سیستم. مراحل: ICMP موازی ← (نام غیرقابل‌حل) IP ثبت‌شده ← بررسی TCP پورت‌های 445/135/3389.
 * بدون وابستگی به اسکریپت PowerShell؛ ۳۰۰ سیستم در چند ثانیه.
 *
 * @param array<int,string> $hosts
 * @param array<string,string> $fallbackIps کلید = نام کامپیوتر (حروف کوچک)، مقدار = IP ثبت‌شده در شناسنامه
 * @return array<string,bool> کلید = نام کامپیوتر (حروف کوچک)
 */
function domain_scan_ping_hosts(array $hosts, array $fallbackIps = []): array
{
    $clean = [];
    foreach ($hosts as $host) {
        $host = trim((string) $host);
        if ($host !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $host)) {
            $clean[strtolower($host)] = $host;
        }
    }
    if ($clean === []) {
        return [];
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    $timeout = domain_scan_timeout_ms();
    $results = array_fill_keys(array_keys($clean), false);

    foreach (domain_scan_icmp_round($clean, $timeout) as $key => $ok) {
        if ($ok) {
            $results[$key] = true;
        }
    }

    // سیستم‌های بی‌پاسخ: IP واقعی را پیدا کن (DNS، و اگر نام حل نشد IP ثبت‌شده در شناسنامه)
    $budgetEnd = microtime(true) + 20.0;
    $ipTargets = [];
    $viaFallback = [];
    foreach ($clean as $key => $host) {
        if ($results[$key] || microtime(true) > $budgetEnd) {
            continue;
        }
        $ip = domain_scan_resolve_ipv4($host);
        if ($ip === '') {
            $stored = trim((string) ($fallbackIps[$key] ?? ''));
            if ($stored !== '' && filter_var($stored, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $stored;
                $viaFallback[$key] = $stored;
            }
        }
        if ($ip !== '') {
            $ipTargets[$key] = $ip;
        }
    }
    if ($viaFallback !== []) {
        foreach (domain_scan_icmp_round($viaFallback, $timeout) as $key => $ok) {
            if ($ok) {
                $results[$key] = true;
                unset($ipTargets[$key]);
            }
        }
    }
    $tcpTargets = [];
    foreach ($ipTargets as $key => $ip) {
        if (!$results[$key]) {
            $tcpTargets[$key] = $ip;
        }
    }
    if ($tcpTargets !== []) {
        foreach (domain_scan_tcp_probe($tcpTargets, [445, 135, 3389], min(2500, max(800, $timeout))) as $key => $_up) {
            $results[$key] = true;
        }
    }
    return $results;
}

function domain_scan_online(string $host): bool
{
    $host = trim($host);
    if ($host === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $host)) {
        return false;
    }
    $result = domain_scan_ping_hosts([$host]);
    return (bool) ($result[strtolower($host)] ?? false);
}

function domain_scan_mark_reachability(int $assetId, bool $online, int $userId): void
{
    if ($assetId <= 0) {
        return;
    }
    asset_profile_ensure_schema();
    $now = date('Y-m-d H:i:s');
    $exists = db()->prepare('SELECT asset_id FROM asset_profiles WHERE asset_id = ? LIMIT 1');
    $exists->execute([$assetId]);
    if ($exists->fetchColumn()) {
        if ($online) {
            db()->prepare('UPDATE asset_profiles SET ad_online = 1, ad_last_seen = ?, ad_scanned_at = ?, updated_by = ?, updated_at = NOW() WHERE asset_id = ?')->execute([$now, $now, $userId > 0 ? $userId : null, $assetId]);
        } else {
            db()->prepare('UPDATE asset_profiles SET ad_online = 0, ad_scanned_at = ?, updated_by = ?, updated_at = NOW() WHERE asset_id = ?')->execute([$now, $userId > 0 ? $userId : null, $assetId]);
        }
        return;
    }
    db()->prepare('INSERT INTO asset_profiles (asset_id, ad_online, ad_last_seen, ad_scanned_at, updated_by) VALUES (?, ?, ?, ?, ?)')->execute([$assetId, $online ? 1 : 0, $online ? $now : null, $now, $userId > 0 ? $userId : null]);
}

/** پیام خطای PowerShell/WMI را به فارسی قابل‌فهم تبدیل می‌کند. */
function domain_scan_friendly_error(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
        $raw = (string) @mb_convert_encoding($raw, 'UTF-8', 'Windows-1256');
    }
    $lower = strtolower($raw);
    $map = [
        'access is denied' => 'دسترسی رد شد؛ حساب اسکن باید «ادمین محلی» همان کامپیوتر باشد (تنظیمات ← اسکن دامنه).',
        '0x80070005' => 'دسترسی رد شد؛ حساب اسکن باید «ادمین محلی» همان کامپیوتر باشد (تنظیمات ← اسکن دامنه).',
        'rpc server is unavailable' => 'سرویس RPC/WMI روی سیستم مقصد در دسترس نیست (فایروال ویندوز یا سرویس WMI را بررسی کنید).',
        '0x800706ba' => 'سرویس RPC/WMI روی سیستم مقصد در دسترس نیست (فایروال ویندوز یا سرویس WMI را بررسی کنید).',
        'logon failure' => 'نام کاربری یا رمز حساب اسکن نادرست است.',
        'user name or password is incorrect' => 'نام کاربری یا رمز حساب اسکن نادرست است.',
        'no_computer_info' => 'اطلاعاتی از سیستم مقصد دریافت نشد؛ دسترسی WMI/DCOM و فایروال را بررسی کنید.',
        'cannot be loaded because running scripts is disabled' => 'اجرای اسکریپت PowerShell با ExecutionPolicy مسدود است.',
    ];
    foreach ($map as $needle => $message) {
        if (str_contains($lower, $needle)) {
            return $message;
        }
    }
    $short = function_exists('mb_substr') ? mb_substr($raw, 0, 220) : substr($raw, 0, 220);
    return preg_replace('/\s+/u', ' ', $short) ?? $short;
}

/**
 * کاتالوگ خطاهای استخراج: هر خطا یک کد ثابت، یک عنوان کوتاه و راه‌حل گام‌به‌گام دارد.
 * صفحهٔ اسکن با همین کاتالوگ، شکست‌ها را گروه‌بندی می‌کند و راه‌حل هر گروه را نشان می‌دهد.
 */
function domain_scan_error_catalog(): array
{
    return [
        'rpc_unavailable' => [
            'title' => 'سرویس RPC/WMI سیستم مقصد جواب نمی‌دهد',
            'remedy' => [
                'روی خودِ سیستم مقصد، سرویس Windows Management Instrumentation را روشن نگه دارید (سرویس Winmgmt).',
                'قواعد فایروال ورودی را فعال کنید: Windows Management Instrumentation (WMI-In)، Remote Service Management (RPC) و در صورت نیاز File and Printer Sharing.',
                'اگر شبکه بین پنل و کلاینت‌ها روی چند VLAN است، پورت‌های TCP 135 و محدودهٔ Dynamic RPC (پیش‌فرض 49152-65535) باز باشد.',
                'برای مسیر جایگزین، روی سرور پنل یک‌بار Enable-PSRemoting -Force اجرا کنید تا مسیر WinRM هم آزموده شود (در تنظیمات اسکن دامنه می‌توانید ترتیب DCOM/WinRM را عوض کنید).',
                'اگر سیستم مقصد خطای دائمی می‌دهد و فایروال قابل تغییر نیست، از client-agent روی همان سیستم استفاده کنید (بخش ۷.۸ راهنما).',
            ],
        ],
        'access_denied' => [
            'title' => 'دسترسی رد شد (حساب اسکن ادمین محلی مقصد نیست)',
            'remedy' => [
                'حساب اسکن را به گروه Administrators خودِ هر کلاینت اضافه کنید؛ روش سریع: یک گروه AD بسازید، حساب اسکن را عضو آن کنید و با Group Policy آن گروه را به Administrators محلی ببرید.',
                'اگر از حساب محلی (غیر دامنه‌ای) استفاده می‌کنید، روی کلاینت مقدار LocalAccountTokenFilterPolicy را در مسیر HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System برابر ۱ بگذارید تا UAC دسترسی از راه دور را محدود نکند.',
                'حساب را در «تنظیمات ← اسکن دامنه و استخراج از شبکه» درست وارد کنید (دامنهٔ NetBIOS + نام کاربری + رمز) و با ابزار tools/diag_connection.php روی یک سیستم آزمون بگیرید.',
                'مطمئن شوید حساب قفل یا منقضی نشده و محدودیت «Log on to» آن اجازهٔ ورود از شبکه را می‌دهد.',
            ],
        ],
        'bad_credentials' => [
            'title' => 'نام کاربری یا رمز حساب اسکن نادرست است',
            'remedy' => [
                'دامنه و نام کاربری را در تنظیمات اسکن دامنه بازبینی کنید (قالب درست: COMPANY\\user).',
                'رمز را دوباره ذخیره کنید؛ اگر رمز حساب عوض شده، همین کادر باید به‌روز شود.',
                'با tools/diag_connection.php --client=<IP> نتیجهٔ آزمون را ببینید.',
            ],
        ],
        'no_computer_info' => [
            'title' => 'اطلاعات رایانه از سیستم مقصد دریافت نشد',
            'remedy' => [
                'نام کامپیوتر یا IP درست را بررسی کنید (ممکن است نام در DNS به سیستمی دیگر اشاره کند).',
                'دسترسی WMI/DCOM همان نکته‌های خطای RPC را لازم دارد؛ فایروال و سرویس WMI را بررسی کنید.',
                'سیستم ممکن است به‌تازگی روشن شده و WMI هنوز آماده نباشد؛ چند دقیقه بعد دوباره تلاش کنید.',
            ],
        ],
        'empty_output' => [
            'title' => 'پاسخ خالی از PowerShell',
            'remedy' => [
                'روی سرور پنل، دستور زیر را اجرا کنید تا اجرای اسکریپت آزاد باشد: Set-ExecutionPolicy -Scope LocalMachine Bypass',
                'اینترنت/آنتی‌ویروس روی سرور پنل ممکن است PowerShell را محدود کرده باشد؛ اسکریپت tools/collect_server_inventory.ps1 را در فهرست استثناها بگذارید.',
            ],
        ],
        'policy_blocked' => [
            'title' => 'اجرای اسکریپت PowerShell روی سرور پنل مسدود است',
            'remedy' => [
                'روی سرور پنل: Set-ExecutionPolicy -Scope LocalMachine RemoteSigned (یا Bypass) و سرویس IIS/Apache را restart کنید.',
                'در سرویس ویندوزی که پنل را اجرا می‌کند، گزینهٔ Group Policy «Turn on Script Execution» باید Allow local scripts باشد.',
            ],
        ],
        'not_windows' => [
            'title' => 'سرور پنل ویندوز نیست',
            'remedy' => [
                'استخراج شناسنامه از راه دور فقط روی سرور Windows کار می‌کند؛ پنل را روی Windows اجرا کنید.',
                'در لینوکس، شناسنامه‌ها را دستی تکمیل کنید یا از client-agent روی کلاینت‌ها استفاده کنید.',
            ],
        ],
        'proc_open_disabled' => [
            'title' => 'تابع proc_open در PHP غیرفعال است',
            'remedy' => [
                'در php.ini مقدار disable_functions را بررسی کنید و proc_open را از آن حذف کنید.',
                'سرویس وب (IIS/Apache) را restart کنید.',
            ],
        ],
        'script_missing' => [
            'title' => 'اسکریپت استخراج روی سرور پیدا نشد',
            'remedy' => [
                'فایل tools/collect_server_inventory.ps1 باید در ریشهٔ برنامه باشد؛ آن را از بستهٔ نصب بازگردانید.',
                'بعد از بازگرداندن فایل، در همین صفحه «تلاش دوباره برای ناموفق‌ها» را بزنید (نیازی به اسکن کامل نیست).',
            ],
        ],
        'timeout' => [
            'title' => 'مهلت استخراج (۴ دقیقه) تمام شد',
            'remedy' => [
                'هر نوبت تعداد کمتری سیستم انتخاب کنید (مثلاً ۱۰ تا ۲۰ سیستم).',
                'سیستم مقصد یا کند است یا WMI آن نیمه‌کاره پاسخ می‌دهد؛ همان سیستم را تنها و جداگانه استخراج کنید.',
            ],
        ],
        'not_reachable' => [
            'title' => 'سیستم در شبکه پاسخ نداد (آفلاین)',
            'remedy' => [
                'سیستم خاموش است یا در شبکه نیست؛ بعد از روشن‌شدن با «تلاش دوباره برای ناموفق‌ها» امتحان کنید.',
                'اگر سیستم روشن است، پاسخ ICMP در فایروال ویندوز بسته است؛ قاعدهٔ File and Printer Sharing (Echo Request - ICMPv4-In) را فعال کنید.',
                'در تنظیمات، پورت‌های TCP بررسی‌شده برای تشخیص آنلاین‌بودن را هم می‌توانید اضافه کنید (نشانی‌های SMb/WMI).',
            ],
        ],
        'bad_hostname' => [
            'title' => 'نام کامپیوتر نامعتبر است',
            'remedy' => [
                'ردیف را در فهرست شناسنامه‌ها اصلاح کنید؛ نام باید فقط شامل حرف، رقم، نقطه و خط تیره باشد.',
                'نام درست را با اجرای دستور hostname روی خودِ سیستم بردارید یا در همان ردیف شناسنامه، IP سیستم را ثبت کنید.',
            ],
        ],
        'store_failed' => [
            'title' => 'ذخیرهٔ اطلاعات در دیتابیس ناموفق بود',
            'remedy' => [
                'پیام دقیق دیتابیس را در «گزارش‌های سیستم» ببینید؛ معمولاً به دلیل طول فیلد یا قطع اتصال است.',
                'اگر دیتابیس جداگانه است، فضای دیسک و دسترسی کاربر دیتابیس را بررسی کنید.',
            ],
        ],
        'unknown' => [
            'title' => 'خطای نامشخص',
            'remedy' => [
                'متن خطا را در جدول پایین همین صفحه بخوانید و در «گزارش‌های سیستم» جزئیات کامل را ببینید.',
                'همین سیستم را تنها با «استخراج آزمایشی» (tools/diag_asset_profile.php یا ابزار tools/diag_connection.php) آزمون کنید.',
            ],
        ],
    ];
}

/** کد خطا را از متن پیامِ ذخیره‌شده در صف استخراج تشخیص می‌دهد. */
function domain_scan_error_code(string $message): string
{
    $message = trim($message);
    if ($message === '') {
        return 'unknown';
    }
    $prefixes = [
        'rpc_unavailable' => ['سرویس RPC/WMI'],
        'access_denied' => ['دسترسی رد شد'],
        'bad_credentials' => ['نام کاربری یا رمز'],
        'no_computer_info' => ['اطلاعاتی از سیستم مقصد'],
        'policy_blocked' => ['اجرای اسکریپت PowerShell'],
        'not_windows' => ['استخراج اطلاعات از سیستم‌های دامنه فقط روی سرور'],
        'proc_open_disabled' => ['تابع proc_open'],
        'script_missing' => ['اسکریپت tools/collect_server_inventory.ps1'],
        'timeout' => ['مهلت استخراج'],
        'not_reachable' => ['در شبکه پاسخ نداد'],
        'bad_hostname' => ['نام کامپیوتر نامعتبر'],
        'empty_output' => ['پاسخ خالی از PowerShell'],
        'store_failed' => ['ذخیرهٔ اطلاعات ناموفق'],
    ];
    foreach ($prefixes as $code => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return $code;
            }
        }
    }
    return 'unknown';
}

/**
 * شکست‌های یک نوبت استخراج را بر اساس علت گروه‌بندی می‌کند.
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array{code:string,title:string,count:int,hosts:array<int,string>,messages:array<int,string>,remedy:array<int,string>}>
 */
function domain_scan_failure_summary(array $rows): array
{
    $catalog = domain_scan_error_catalog();
    $groups = [];
    foreach ($rows as $row) {
        $status = (string) ($row['status'] ?? '');
        if ($status !== 'error' && $status !== 'offline') {
            continue;
        }
        $message = (string) ($row['message'] ?? '');
        $code = $status === 'offline' ? 'not_reachable' : domain_scan_error_code($message);
        if (!isset($groups[$code])) {
            $groups[$code] = [
                'code' => $code,
                'title' => (string) ($catalog[$code]['title'] ?? $catalog['unknown']['title']),
                'count' => 0,
                'hosts' => [],
                'messages' => [],
                'remedy' => (array) ($catalog[$code]['remedy'] ?? $catalog['unknown']['remedy']),
            ];
        }
        $groups[$code]['count']++;
        if (count($groups[$code]['hosts']) < 12 && trim((string) ($row['hostname'] ?? '')) !== '') {
            $hostLine = trim((string) $row['hostname']);
            $groups[$code]['hosts'][] = function_exists('asset_display_name') ? asset_display_name($hostLine) : $hostLine;
        }
        if ($message !== '' && !in_array($message, $groups[$code]['messages'], true) && count($groups[$code]['messages']) < 3) {
            $groups[$code]['messages'][] = $message;
        }
    }
    usort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
    return $groups;
}

/**
 * «تلاش دوباره برای ناموفق‌ها»: نوبت تازه‌ای می‌سازد که فقط سیستم‌های خطادار نوبت قبل در آن است.
 * (سیستم‌های آفلاین و ردیف‌های انجام‌شده تکرار نمی‌شوند.)
 */
function domain_scan_retry_failed(int $runId, int $userId): array
{
    domain_scan_ensure_schema();
    $query = db()->prepare('SELECT hostname, asset_id FROM domain_scan_queue WHERE run_id = ? AND status = "error" ORDER BY id ASC');
    $query->execute([$runId]);
    $rows = $query->fetchAll();
    if ($rows === []) {
        return ['run_id' => 0, 'total' => 0];
    }
    db()->prepare('INSERT INTO domain_scan_runs (started_by, status, total) VALUES (?, "running", ?)')->execute([$userId, count($rows)]);
    $newRunId = (int) db()->lastInsertId();
    $insert = db()->prepare('INSERT INTO domain_scan_queue (run_id, asset_id, hostname, status) VALUES (?, ?, ?, "pending")');
    foreach ($rows as $row) {
        $insert->execute([$newRunId, (int) ($row['asset_id'] ?? 0) ?: null, (string) $row['hostname']]);
    }
    return ['run_id' => $newRunId, 'total' => count($rows)];
}

/** آدرس/نام‌های همین سرور (برای استخراج محلی بدون ساخت نشست از راه دور). */
function domain_scan_local_names(): array
{
    $names = ['localhost', '127.0.0.1', '::1'];
    $host = function_exists('gethostname') ? (string) @gethostname() : '';
    if ($host !== '') {
        $names[] = $host;
        $short = explode('.', $host)[0];
        if ($short !== '') {
            $names[] = $short;
        }
        $resolved = @gethostbyname($host);
        if (is_string($resolved) && $resolved !== '') {
            $names[] = $resolved;
        }
    }
    foreach (['SERVER_NAME', 'SERVER_ADDR'] as $key) {
        $value = trim((string) ($_SERVER[$key] ?? ''));
        if ($value !== '') {
            $names[] = $value;
        }
    }
    return array_values(array_unique(array_filter(array_map(static fn ($v): string => strtolower(trim((string) $v)), $names))));
}

/** پیش‌نیازهای استخراج را بررسی می‌کند؛ در صورت مشکل، پیام روشن برمی‌گرداند. */
function domain_scan_preflight(): ?string
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return 'استخراج اطلاعات از سیستم‌های دامنه فقط روی سرور Windows (با PowerShell) کار می‌کند.';
    }
    if (!function_exists('proc_open')) {
        return 'تابع proc_open در PHP غیرفعال است؛ آن را از disable_functions در php.ini حذف کنید.';
    }
    if (!is_file(inventory_server_script_path())) {
        return 'اسکریپت tools/collect_server_inventory.ps1 پیدا نشد.';
    }
    return null;
}

/**
 * استخراج سخت‌افزار/نرم‌افزار چند سیستم به‌صورت موازی (هر سیستم یک PowerShell با مهلت مشخص).
 *
 * @param array<int,string> $hosts
 * @return array<string,array{inventory:array<string,mixed>,error:string}> کلید = نام کامپیوتر
 */
function domain_scan_remote_inventory_many(array $hosts, int $concurrency = 4): array
{
    $answer = [];
    $problem = domain_scan_preflight();
    $jobs = [];
    $user = trim((string) setting('domain_scan_username', ''));
    $pass = (string) setting('domain_scan_password', '');
    $domain = trim((string) setting('domain_scan_domain', ''));
    if ($user !== '' && strpos($user, '\\') === false && strpos($user, '@') === false && $domain !== '') {
        $user = $domain . '\\' . $user;
    }
    $env = [];
    if ($user !== '') {
        $env['ITSM_SCAN_USER'] = $user;
        if ($pass !== '') {
            $env['ITSM_SCAN_PASS'] = $pass;
        }
    }
    // ترتیب پروتکل‌های اتصال (DCOM/WinRM): اگر در تنظیمات مشخص شده باشد به اسکریپت می‌رسد.
    $protocols = trim((string) setting('domain_scan_protocols', ''));
    if ($protocols !== '') {
        $env['ITSM_SCAN_PROTOCOLS'] = $protocols;
    }
    $powershell = domain_scan_powershell_path();
    $script = inventory_server_script_path();
    $localNames = domain_scan_local_names();
    foreach ($hosts as $host) {
        $host = trim((string) $host);
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $host)) {
            $answer[$host] = ['inventory' => [], 'error' => 'نام کامپیوتر نامعتبر است.'];
            continue;
        }
        if ($problem !== null) {
            $answer[$host] = ['inventory' => [], 'error' => $problem];
            continue;
        }
        // سیستم خودِ سرور: بدون نشست از راه دور (WMI/DCOM) خوانده می‌شود؛ این مسیر هرگز
        // به خطای «دسترسی رد شد» یا «RPC در دسترس نیست» نمی‌خورد.
        $isLocal = in_array(strtolower($host), $localNames, true);
        $command = [$powershell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script];
        if (!$isLocal) {
            $command[] = '-ComputerName';
            $command[] = $host;
        }
        $jobs[$host] = [
            'cmd' => $command,
            'env' => $isLocal ? [] : $env,
        ];
    }
    if ($jobs === []) {
        return $answer;
    }
    $results = domain_scan_run_processes($jobs, $concurrency, 240);
    foreach ($jobs as $host => $_job) {
        $row = $results[$host] ?? ['code' => -1, 'out' => '', 'err' => '', 'timed_out' => false, 'started' => false];
        if ($row['timed_out']) {
            $answer[$host] = ['inventory' => [], 'error' => 'مهلت استخراج (۴ دقیقه) تمام شد؛ سیستم بسیار کند است یا WMI پاسخ نمی‌دهد.'];
            continue;
        }
        $out = ltrim(trim($row['out']), "\xEF\xBB\xBF");
        $decoded = $out !== '' ? json_decode($out, true) : null;
        if (!is_array($decoded)) {
            $detail = domain_scan_friendly_error(trim($row['err']) !== '' ? $row['err'] : $row['out']);
            $answer[$host] = ['inventory' => [], 'error' => $detail !== '' ? $detail : 'پاسخ خالی از PowerShell (کد خروج ' . $row['code'] . ').'];
            continue;
        }
        $inventory = inventory_base_inventory();
        $inventory['hostname'] = $host;
        $inventory['computer_type'] = 'Windows client';
        $resolved = domain_scan_resolve_ipv4($host);
        $inventory['ip_address'] = $resolved;
        $answer[$host] = ['inventory' => inventory_apply_powershell_data($inventory, $decoded), 'error' => ''];
    }
    return $answer;
}

function domain_scan_remote_inventory(string $host): array
{
    $GLOBALS['itsm_inventory_last_error'] = '';
    $host = trim($host);
    $many = domain_scan_remote_inventory_many([$host], 1);
    $row = $many[$host] ?? ['inventory' => [], 'error' => 'نتیجه‌ای دریافت نشد.'];
    if ($row['inventory'] === []) {
        inventory_set_error($host . ': ' . $row['error']);
        return [];
    }
    return $row['inventory'];
}

function domain_scan_store_inventory(int $assetId, string $host, array $inventory, int $userId): int
{
    if ($assetId <= 0) {
        return 0;
    }
    db()->prepare('UPDATE assets SET hostname = ?, serial_number = ?, manufacturer = ?, model = ?, computer_type = ?, operating_system = ?, os_architecture = ?, os_serial = ?, ip_address = ?, mac_address = ?, cpu = ?, memory_mb = ?, disks_json = ?, software_json = ?, hardware_json = ?, peripherals_json = ?, antivirus = ?, source = "domain", last_inventory_at = NOW() WHERE id = ?')->execute([
        $host,
        (string) ($inventory['serial_number'] ?? ''),
        (string) ($inventory['manufacturer'] ?? ''),
        (string) ($inventory['model'] ?? ''),
        (string) ($inventory['computer_type'] ?? 'Windows client'),
        (string) ($inventory['operating_system'] ?? ''),
        (string) ($inventory['os_architecture'] ?? ''),
        (string) ($inventory['os_serial'] ?? ''),
        (string) ($inventory['ip_address'] ?? ''),
        (string) ($inventory['mac_address'] ?? ''),
        (string) ($inventory['cpu'] ?? ''),
        max(0, (int) ($inventory['memory_mb'] ?? 0)),
        json_encode($inventory['disks'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($inventory['software'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($inventory['hardware'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($inventory['peripherals'] ?? [], JSON_UNESCAPED_UNICODE),
        (string) ($inventory['antivirus'] ?? ''),
        $assetId,
    ]);
    sync_inventory_rows($assetId, $inventory, 'domain');
    if (inventory_asset_exists($assetId)) {
        db()->prepare('INSERT INTO asset_inventory_history (asset_id, snapshot_json, collected_by) VALUES (?, ?, ?)')->execute([$assetId, json_encode($inventory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId > 0 ? $userId : null]);
    }
    return asset_profile_apply_auto($assetId, asset_profile_auto_values($inventory), $userId);
}

function domain_scan_process(int $runId, int $limit, int $userId): array
{
    domain_scan_ensure_schema();
    if (function_exists('set_time_limit')) { @set_time_limit(600); }
    if (function_exists('session_write_close')) { @session_write_close(); }
    $limit = max(1, min(40, $limit));
    db()->prepare('UPDATE domain_scan_queue SET status = "pending" WHERE run_id = ? AND status = "running" AND updated_at < (NOW() - INTERVAL 10 MINUTE)')->execute([$runId]);
    $query = db()->prepare('SELECT * FROM domain_scan_queue WHERE run_id = ? AND status = "pending" ORDER BY id ASC LIMIT ' . $limit);
    $query->execute([$runId]);
    $items = $query->fetchAll();

    if ($items !== []) {
        $markRunning = db()->prepare('UPDATE domain_scan_queue SET status = "running" WHERE id = ?');
        $hosts = [];
        $assetIds = [];
        foreach ($items as $item) {
            $markRunning->execute([(int) $item['id']]);
            $hosts[] = (string) $item['hostname'];
            if ((int) ($item['asset_id'] ?? 0) > 0) {
                $assetIds[] = (int) $item['asset_id'];
            }
        }
        // IP ثبت‌شده در شناسنامه: اگر نام کامپیوتر در DNS حل نشد، از آن استفاده می‌شود.
        $fallbackIps = [];
        if ($assetIds !== []) {
            $ipQuery = db()->prepare('SELECT LOWER(hostname) AS h, ip_address FROM assets WHERE id IN (' . implode(',', array_fill(0, count($assetIds), '?')) . ')');
            $ipQuery->execute($assetIds);
            foreach ($ipQuery->fetchAll() as $row) {
                if (trim((string) $row['ip_address']) !== '') {
                    $fallbackIps[(string) $row['h']] = trim((string) $row['ip_address']);
                }
            }
        }
        // ۱) پینگ هم‌زمان همهٔ سیستم‌های این نوبت
        $pingResults = domain_scan_ping_hosts($hosts, $fallbackIps);
        $onlineItems = [];
        foreach ($items as $item) {
            $queueId = (int) $item['id'];
            $assetId = (int) ($item['asset_id'] ?? 0);
            $host = (string) $item['hostname'];
            if (!($pingResults[strtolower($host)] ?? false)) {
                db()->prepare('UPDATE domain_scan_queue SET status = "offline", online = 0, message = "در شبکه پاسخ نداد" WHERE id = ?')->execute([$queueId]);
                if ($assetId > 0) {
                    domain_scan_mark_reachability($assetId, false, $userId);
                }
                db()->prepare('UPDATE domain_scan_runs SET offline = offline + 1, done = done + 1 WHERE id = ?')->execute([$runId]);
                continue;
            }
            if ($assetId > 0) {
                domain_scan_mark_reachability($assetId, true, $userId);
            }
            $onlineItems[] = $item;
        }
        // ۲) استخراج هم‌زمان سیستم‌های آنلاین
        if ($onlineItems !== []) {
            $remote = [];
            try {
                $remote = domain_scan_remote_inventory_many(array_map(static fn (array $row): string => (string) $row['hostname'], $onlineItems), 4);
            } catch (Throwable $exception) {
                error_log('[domain-scan] remote inventory: ' . $exception->getMessage());
            }
            foreach ($onlineItems as $item) {
                $queueId = (int) $item['id'];
                $assetId = (int) ($item['asset_id'] ?? 0);
                $host = (string) $item['hostname'];
                try {
                    $row = $remote[$host] ?? ['inventory' => [], 'error' => 'نتیجه‌ای از استخراج دریافت نشد.'];
                    if ($row['inventory'] === []) {
                        $message = (string) ($row['error'] ?: 'خطای نامشخص');
                        db()->prepare('UPDATE domain_scan_queue SET status = "error", online = 1, message = ? WHERE id = ?')->execute([function_exists('mb_substr') ? mb_substr($message, 0, 250) : substr($message, 0, 250), $queueId]);
                        if (function_exists('system_log')) {
                            system_log('error', 'domain_scan', 'استخراج ناموفق: ' . $host, ['run' => $runId, 'error' => $message]);
                        }
                        db()->prepare('UPDATE domain_scan_runs SET failed = failed + 1, done = done + 1 WHERE id = ?')->execute([$runId]);
                        continue;
                    }
                    $filled = domain_scan_store_inventory($assetId, $host, $row['inventory'], $userId);
                    if ($assetId > 0) {
                        asset_profile_apply_auto($assetId, ['ad_online' => 1, 'ad_last_seen' => date('Y-m-d H:i:s'), 'ad_scanned_at' => date('Y-m-d H:i:s')], $userId, true);
                        record_asset_history($assetId, 'inventory_collected', 'استخراج از شبکه دامنه', $userId, null, 'اطلاعات سخت‌افزاری از طریق دامنه دریافت شد.');
                    }
                    db()->prepare('UPDATE domain_scan_queue SET status = "done", online = 1, message = ? WHERE id = ?')->execute([$filled > 0 ? ($filled . ' فیلد شناسنامه پر شد') : 'اطلاعات تازه‌ای نبود', $queueId]);
                    db()->prepare('UPDATE domain_scan_runs SET online = online + 1, done = done + 1 WHERE id = ?')->execute([$runId]);
                } catch (Throwable $exception) {
                    db()->prepare('UPDATE domain_scan_queue SET status = "error", online = 1, message = ? WHERE id = ?')->execute([substr('ذخیرهٔ اطلاعات ناموفق: ' . $exception->getMessage(), 0, 250), $queueId]);
                    db()->prepare('UPDATE domain_scan_runs SET failed = failed + 1, done = done + 1 WHERE id = ?')->execute([$runId]);
                }
            }
        }
    }
    $remainingQuery = db()->prepare('SELECT COUNT(*) FROM domain_scan_queue WHERE run_id = ? AND status IN ("pending", "running")');
    $remainingQuery->execute([$runId]);
    $pending = (int) $remainingQuery->fetchColumn();
    if ($pending === 0) {
        db()->prepare('UPDATE domain_scan_runs SET status = "finished" WHERE id = ?')->execute([$runId]);
    }
    return ['pending' => $pending, 'processed' => count($items)];
}

function domain_scan_run(int $runId): ?array
{
    domain_scan_ensure_schema();
    $query = db()->prepare('SELECT * FROM domain_scan_runs WHERE id = ? LIMIT 1');
    $query->execute([$runId]);
    $run = $query->fetch();
    return $run ?: null;
}

function domain_scan_queue_rows(int $runId, int $limit = 100): array
{
    $query = db()->prepare('SELECT * FROM domain_scan_queue WHERE run_id = ? ORDER BY (status = "error") DESC, (status = "offline") DESC, id ASC LIMIT ' . max(1, min(1000, $limit)));
    $query->execute([$runId]);
    return $query->fetchAll();
}

function domain_scan_latest_run(): ?array
{
    domain_scan_ensure_schema();
    $query = db()->query('SELECT * FROM domain_scan_runs ORDER BY id DESC LIMIT 1');
    $run = $query->fetch();
    return $run ?: null;
}

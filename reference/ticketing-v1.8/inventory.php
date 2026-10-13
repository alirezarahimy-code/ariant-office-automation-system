<?php
declare(strict_types=1);

function inventory_command(string $command): string
{
    if (!function_exists('shell_exec')) {
        return '';
    }
    $result = @shell_exec($command);
    return is_string($result) ? trim($result) : '';
}

function inventory_json(string $command): array
{
    $raw = inventory_command($command);
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_is_list($decoded) ? $decoded : [$decoded];
}

function inventory_last_error(): string
{
    $error = $GLOBALS['itsm_inventory_last_error'] ?? '';
    return is_string($error) ? $error : '';
}

function inventory_set_error(string $message): void
{
    $GLOBALS['itsm_inventory_last_error'] = $message;
    if (function_exists('system_log')) {
        system_log('error', 'inventory_collect', $message);
    }
}

function inventory_server_script_path(): string
{
    return __DIR__ . '/tools/collect_server_inventory.ps1';
}

function inventory_local_hostnames(): array
{
    $host = strtolower(trim((string) (gethostname() ?: php_uname('n'))));
    if ($host === '') {
        return [];
    }
    $short = strtolower(explode('.', $host)[0]);
    return array_values(array_unique(array_filter([$host, $short])));
}

function inventory_server_asset_tag(): string
{
    $host = trim((string) (gethostname() ?: php_uname('n')));
    return 'SERVER-' . strtoupper((string) preg_replace('/[^A-Za-z0-9_-]/', '-', $host !== '' ? $host : 'HOST'));
}

function inventory_asset_is_local(array $asset): bool
{
    $tag = strtoupper(trim((string) ($asset['asset_tag'] ?? '')));
    if ($tag !== '' && $tag === inventory_server_asset_tag()) {
        return true;
    }
    $assetHost = strtolower(trim((string) ($asset['hostname'] ?? '')));
    return $assetHost !== '' && in_array($assetHost, inventory_local_hostnames(), true);
}

function inventory_run_powershell_file(string $scriptPath): array
{
    if (!function_exists('shell_exec')) {
        inventory_set_error('تابع shell_exec در PHP غیرفعال است؛ سرور نمی‌تواند PowerShell را اجرا کند.');
        return [];
    }
    if (!is_file($scriptPath)) {
        inventory_set_error('فایل اسکریپت جمع‌آوری یافت نشد: ' . $scriptPath);
        return [];
    }
    $raw = inventory_command('powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' . escapeshellarg($scriptPath) . ' 2>&1');
    if ($raw === '') {
        inventory_set_error('PowerShell هیچ خروجی نداد. دسترسی حساب سرویس وب به PowerShell و فعال بودن shell_exec را بررسی کنید.');
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $snippet = function_exists('mb_substr') ? mb_substr($raw, 0, 300) : substr($raw, 0, 300);
        inventory_set_error('خروجی PowerShell قابل خواندن نبود: ' . $snippet);
        return [];
    }
    return $decoded;
}

function inventory_diagnostics(): array
{
    $checks = [];
    $checks[] = ['سیستم‌عامل سرور', PHP_OS_FAMILY . ' ' . php_uname('r')];
    $checks[] = ['shell_exec فعال است؟', function_exists('shell_exec') ? 'بله' : 'خیر'];
    $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    $checks[] = ['shell_exec در disable_functions', in_array('shell_exec', $disabled, true) ? 'بله — غیرفعال است' : 'خیر'];
    $checks[] = ['نام سرور (hostname)', (string) (gethostname() ?: php_uname('n'))];
    if (PHP_OS_FAMILY !== 'Windows') {
        $checks[] = ['مسیر powershell', 'سیستم‌عامل سرور ویندوز نیست؛ استخراج سخت‌افزار فقط از روی سرور ویندوز انجام می‌شود.'];
        return $checks;
    }
    $powershellPath = inventory_command('where powershell');
    $checks[] = ['مسیر powershell', $powershellPath !== '' ? $powershellPath : 'پیدا نشد'];
    $scriptPath = inventory_server_script_path();
    $checks[] = ['فایل اسکریپت جمع‌آوری', is_file($scriptPath) ? 'موجود است' : 'یافت نشد: ' . $scriptPath];
    $data = inventory_run_powershell_file($scriptPath);
    if ($data === []) {
        $checks[] = ['اجرای جمع‌آوری', 'ناموفق'];
        $checks[] = ['پیام خطا', inventory_last_error() ?: 'خطای ناشناخته'];
        return $checks;
    }
    $checks[] = ['اجرای جمع‌آوری', 'موفق'];
    foreach (['computer' => 'مشخصات رایانه', 'bios' => 'بایوس', 'processor' => 'پردازنده', 'os' => 'سیستم‌عامل', 'memory_modules' => 'ماژول‌های RAM', 'physical_disks' => 'هاردها', 'graphics' => 'کارت گرافیک', 'network' => 'کارت شبکه', 'printers' => 'پرینترها', 'software' => 'نرم‌افزارها'] as $key => $label) {
        $checks[] = ['تعداد ' . $label, (string) count(inventory_rows($data[$key] ?? []))];
    }
    return $checks;
}

function inventory_value(array $row, array $keys, mixed $default = ''): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) {
            return $row[$key];
        }
    }
    return $default;
}

function inventory_rows(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    return array_is_list($value) ? $value : [$value];
}

function inventory_asset_exists(int $assetId): bool
{
    if ($assetId <= 0) {
        return false;
    }
    $query = db()->prepare('SELECT 1 FROM assets WHERE id = ? LIMIT 1');
    $query->execute([$assetId]);
    return (bool) $query->fetchColumn();
}

function sync_inventory_rows(int $assetId, array $inventory, string $source = 'agent'): void
{
    if ($assetId <= 0) {
        return;
    }
    $source = in_array($source, ['agent', 'server_inventory'], true) ? $source : 'agent';
    $hardware = is_array($inventory['hardware'] ?? null) ? $inventory['hardware'] : [];
    db()->prepare('DELETE FROM asset_memory_modules WHERE asset_id = ?')->execute([$assetId]);
    foreach (inventory_rows($hardware['memory_modules'] ?? []) as $slot => $row) {
        if (!is_array($row)) { continue; }
        $capacity = (int) inventory_value($row, ['Capacity', 'capacity'], 0);
        $capacityMb = $capacity > 100000 ? (int) round($capacity / 1048576) : $capacity;
        db()->prepare('INSERT INTO asset_memory_modules (asset_id, slot_no, manufacturer, model, capacity_mb, speed_mhz, serial_number, collected_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')->execute([$assetId, $slot + 1, (string) inventory_value($row, ['Manufacturer', 'manufacturer']), (string) inventory_value($row, ['PartNumber', 'Model', 'model']), $capacityMb ?: null, (int) inventory_value($row, ['Speed', 'speed'], 0) ?: null, (string) inventory_value($row, ['SerialNumber', 'serial_number'])]);
    }
    db()->prepare('DELETE FROM asset_storage_devices WHERE asset_id = ?')->execute([$assetId]);
    foreach (inventory_rows($hardware['physical_disks'] ?? $inventory['physical_disks'] ?? []) as $slot => $row) {
        if (!is_array($row)) { continue; }
        db()->prepare('INSERT INTO asset_storage_devices (asset_id, slot_no, device_type, model, capacity_bytes, serial_number, media_type, interface_type, collected_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')->execute([$assetId, $slot + 1, 'physical', (string) inventory_value($row, ['Model', 'model']), (int) inventory_value($row, ['Size', 'size'], 0) ?: null, (string) inventory_value($row, ['SerialNumber', 'serial_number']), (string) inventory_value($row, ['MediaType', 'media_type']), (string) inventory_value($row, ['InterfaceType', 'interface_type'])]);
    }
    db()->prepare('DELETE FROM asset_graphics_adapters WHERE asset_id = ?')->execute([$assetId]);
    foreach (inventory_rows($hardware['graphics'] ?? $inventory['graphics'] ?? []) as $slot => $row) {
        if (!is_array($row)) { continue; }
        $vram = (int) inventory_value($row, ['AdapterRAM', 'vram_mb'], 0);
        $vramMb = $vram > 100000 ? (int) round($vram / 1048576) : $vram;
        db()->prepare('INSERT INTO asset_graphics_adapters (asset_id, slot_no, model, vram_mb, driver_version, collected_at) VALUES (?, ?, ?, ?, ?, NOW())')->execute([$assetId, $slot + 1, (string) inventory_value($row, ['Name', 'model']), $vramMb ?: null, (string) inventory_value($row, ['DriverVersion', 'driver_version'])]);
    }
    $peripherals = is_array($inventory['peripherals'] ?? null) ? $inventory['peripherals'] : [];
    db()->prepare('DELETE FROM asset_peripherals WHERE asset_id = ?')->execute([$assetId]);
    foreach (['printers' => 'printer', 'scanners' => 'scanner', 'monitors' => 'monitor', 'keyboards' => 'keyboard', 'mice' => 'mouse'] as $key => $type) {
        foreach (inventory_rows($peripherals[$key] ?? []) as $slot => $row) {
            if (!is_array($row)) { continue; }
            $model = (string) inventory_value($row, ['Name', 'FriendlyName', 'model']);
            if ($model === '') { continue; }
            db()->prepare('INSERT INTO asset_peripherals (asset_id, peripheral_type, slot_no, model, manufacturer, serial_number, details_json, source, collected_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')->execute([$assetId, $type, $slot + 1, $model, (string) inventory_value($row, ['Manufacturer', 'manufacturer']), (string) inventory_value($row, ['SerialNumber', 'serial_number']), json_encode($row, JSON_UNESCAPED_UNICODE), $source]);
        }
    }
}

function inventory_hardware_score(array $inventory): int
{
    $hardware = is_array($inventory['hardware'] ?? null) ? $inventory['hardware'] : [];
    $score = 0;
    foreach (['processor', 'operating_system', 'bios', 'motherboard', 'physical_disks', 'memory_modules', 'graphics', 'network_adapters'] as $key) {
        if (count(inventory_rows($hardware[$key] ?? [])) > 0) {
            $score++;
        }
    }
    return $score;
}

function inventory_collect_server_reliable(): array
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    $inventory = collect_server_inventory();
    if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec') && inventory_hardware_score($inventory) === 0) {
        $GLOBALS['itsm_inventory_last_error'] = '';
        $retry = collect_server_inventory();
        if (inventory_hardware_score($retry) > inventory_hardware_score($inventory)) {
            return $retry;
        }
    }
    return $inventory;
}

function inventory_server_asset_id(): int
{
    $conditions = ['asset_tag = ?'];
    $params = [inventory_server_asset_tag()];
    $host = trim((string) (gethostname() ?: php_uname('n')));
    if ($host !== '') {
        $conditions[] = 'LOWER(hostname) = LOWER(?)';
        $params[] = $host;
    }
    $query = db()->prepare('SELECT id FROM assets WHERE (' . implode(' OR ', $conditions) . ') ORDER BY id ASC LIMIT 1');
    $query->execute($params);
    return (int) ($query->fetchColumn() ?: 0);
}

function inventory_server_profile_is_empty(): bool
{
    $assetId = inventory_server_asset_id();
    if ($assetId <= 0) {
        return true;
    }
    if (!function_exists('asset_profile_fetch')) {
        return false;
    }
    $profile = asset_profile_fetch($assetId);
    $keys = ['os_name', 'os_version', 'cpu_model', 'ram_total', 'disk1_model', 'motherboard_model', 'motherboard_serial', 'ip_address', 'mac_address'];
    $filled = 0;
    foreach ($keys as $key) {
        if (trim((string) ($profile[$key] ?? '')) !== '') {
            $filled++;
        }
    }
    return $filled < 3;
}

function inventory_auto_extract_server(int $userId): ?int
{
    if (PHP_OS_FAMILY !== 'Windows' || !function_exists('shell_exec') || !function_exists('asset_profile_apply_auto')) {
        return null;
    }
    if (!inventory_server_profile_is_empty()) {
        return null;
    }
    $inventory = inventory_collect_server_reliable();
    if (inventory_hardware_score($inventory) === 0) {
        return null;
    }
    $assetId = inventory_upsert_server_asset($inventory, $userId);
    if ($assetId <= 0) {
        return null;
    }
    $filled = asset_profile_apply_auto($assetId, asset_profile_auto_values($inventory), $userId, false);
    system_log('info', 'inventory', 'استخراج خودکار شناسنامه سرور در اولین ورود', ['asset_id' => $assetId, 'filled' => $filled]);
    return $assetId;
}

function inventory_base_inventory(): array
{
    $hostname = gethostname() ?: php_uname('n');
    return [
        'hostname' => $hostname,
        'serial_number' => '',
        'manufacturer' => '',
        'model' => '',
        'computer_type' => PHP_OS_FAMILY === 'Windows' ? 'Windows Server' : (PHP_OS_FAMILY . ' Server'),
        'operating_system' => PHP_OS_FAMILY . ' ' . php_uname('r'),
        'os_architecture' => php_uname('m'),
        'os_serial' => '',
        'ip_address' => $_SERVER['SERVER_ADDR'] ?? gethostbyname($hostname),
        'mac_address' => '',
        'cpu' => php_uname('m'),
        'memory_mb' => 0,
        'domain_username' => $_SERVER['AUTH_USER'] ?? ($_SERVER['REMOTE_USER'] ?? ''),
        'antivirus' => '',
        'disks' => [],
        'software' => [],
        'peripherals' => [
            'printers' => [],
            'scanners' => [],
            'monitors' => [],
            'keyboards' => [],
            'mice' => [],
        ],
        'hardware' => [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '',
            'os_release' => php_uname('a'),
        ],
        'collected_at' => date('c'),
    ];
}

function inventory_apply_powershell_data(array $inventory, array $data): array
{
    $rows = static fn (string $key): array => inventory_rows($data[$key] ?? []);
    $computer = $rows('computer')[0] ?? [];
    $bios = $rows('bios')[0] ?? [];
    $processor = $rows('processor')[0] ?? [];
    $os = $rows('os')[0] ?? [];
    $motherboard = $rows('motherboard')[0] ?? [];
    $memoryModules = $rows('memory_modules');
    $physicalDisks = $rows('physical_disks');
    $gpu = $rows('graphics');
    $sound = $rows('sound');
    $opticalDrives = $rows('optical_drives');
    $networkAdapters = $rows('network_adapters');
    $modems = $rows('modems');
    $localUsers = $rows('os_users');
    $disks = $rows('disks');
    $network = $rows('network');
    $printers = $rows('printers');
    $scanners = $rows('scanners');
    $monitors = $rows('monitors') ?: $rows('monitors_pnp');
    $keyboards = $rows('keyboards');
    $mice = $rows('mice');
    $antivirus = $rows('antivirus');
    $software = $rows('software');
    $inventory['serial_number'] = (string) ($bios['SerialNumber'] ?? '');
    $inventory['manufacturer'] = (string) ($computer['Manufacturer'] ?? '');
    $inventory['model'] = (string) ($computer['Model'] ?? '');
    $inventory['cpu'] = (string) ($processor['Name'] ?? '') !== '' ? (string) $processor['Name'] : $inventory['cpu'];
    $inventory['memory_mb'] = (int) round(((int) ($computer['TotalPhysicalMemory'] ?? 0)) / 1048576);
    $inventory['domain_username'] = (string) ($computer['UserName'] ?? $inventory['domain_username']);
    $inventory['operating_system'] = (string) ($os['Caption'] ?? $inventory['operating_system']);
    $inventory['os_architecture'] = (string) ($os['OSArchitecture'] ?? $inventory['os_architecture']);
    $inventory['os_serial'] = (string) ($os['SerialNumber'] ?? '');
    $antivirusNames = [];
    foreach ($antivirus as $item) {
        if (!is_array($item)) { continue; }
        $antivirusName = trim((string) ($item['displayName'] ?? ''));
        if ($antivirusName !== '') { $antivirusNames[] = $antivirusName; }
    }
    $inventory['antivirus'] = implode(', ', array_unique($antivirusNames));
    $inventory['hardware'] = array_merge($inventory['hardware'], ['manufacturer' => $computer['Manufacturer'] ?? '', 'model' => $computer['Model'] ?? '', 'user' => $computer['UserName'] ?? '', 'bios' => $bios, 'processor' => $processor, 'operating_system' => $os, 'motherboard' => $motherboard, 'memory_modules' => $memoryModules, 'physical_disks' => $physicalDisks, 'graphics' => $gpu, 'sound' => $sound, 'optical_drives' => $opticalDrives, 'network_adapters' => $networkAdapters, 'modems' => $modems, 'logical_disks' => $disks]);
    $inventory['os_users'] = $localUsers;
    $inventory['disks'] = $disks;
    $inventory['software'] = $software;
    $inventory['peripherals'] = ['printers' => $printers, 'scanners' => $scanners, 'monitors' => $monitors, 'keyboards' => $keyboards, 'mice' => $mice];
    $inventory['mac_address'] = is_array($network[0] ?? null) ? (string) inventory_value($network[0], ['MACAddress'], '') : '';
    return $inventory;
}

function collect_server_inventory(): array
{
    $inventory = inventory_base_inventory();

    if (PHP_OS_FAMILY === 'Windows') {
        $inventory = inventory_apply_powershell_data($inventory, inventory_run_powershell_file(inventory_server_script_path()));
    } else {
        $memory = inventory_command("grep MemTotal /proc/meminfo 2>/dev/null | awk '{print \$2}'");
        $inventory['memory_mb'] = (int) round(((int) $memory) / 1024);
        $inventory['cpu'] = inventory_command("awk -F: '/model name/ {print \$2; exit}' /proc/cpuinfo 2>/dev/null") ?: php_uname('p');
        $inventory['disks'] = [['filesystem' => '/', 'usage' => inventory_command("df -h / 2>/dev/null | awk 'NR==2 {print \$3 \" / \" \$2 \" (\" \$5 \")\"}'")]];
        $inventory['software'] = [['name' => 'PHP', 'version' => PHP_VERSION], ['name' => 'Web server', 'version' => $_SERVER['SERVER_SOFTWARE'] ?? '']];
        $inventory['domain_username'] = get_current_user();
        $inventory['mac_address'] = inventory_command("ip link 2>/dev/null | awk '/link\\/ether/ {print \$2; exit}'");
    }
    return $inventory;
}

function inventory_upsert_server_asset(array $inventory, int $userId): int
{
    $host = trim((string) ($inventory['hostname'] ?? ''));
    $serial = trim((string) ($inventory['serial_number'] ?? ''));
    $assetTag = inventory_server_asset_tag();
    $conditions = ['asset_tag = ?'];
    $params = [$assetTag];
    if ($host !== '') {
        $conditions[] = 'LOWER(hostname) = LOWER(?)';
        $params[] = $host;
    }
    if ($serial !== '') {
        $conditions[] = 'serial_number = ?';
        $params[] = $serial;
    }
    $assetQuery = db()->prepare('SELECT id FROM assets WHERE (' . implode(' OR ', $conditions) . ') ORDER BY id ASC LIMIT 1');
    $assetQuery->execute($params);
    $assetId = (int) ($assetQuery->fetchColumn() ?: 0);
    $payload = json_encode($inventory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $values = [$inventory['hostname'], $inventory['serial_number'], $inventory['manufacturer'], $inventory['model'], $inventory['operating_system'], $inventory['os_architecture'], $inventory['os_serial'], $inventory['ip_address'], $inventory['mac_address'], $inventory['cpu'], $inventory['memory_mb'], json_encode($inventory['disks'], JSON_UNESCAPED_UNICODE), json_encode($inventory['software'], JSON_UNESCAPED_UNICODE), json_encode($inventory['hardware'], JSON_UNESCAPED_UNICODE), json_encode($inventory['peripherals'], JSON_UNESCAPED_UNICODE), $inventory['domain_username'], $inventory['antivirus']];
    if ($assetId > 0) {
        db()->prepare('UPDATE assets SET hostname = ?, serial_number = ?, manufacturer = ?, model = ?, operating_system = ?, os_architecture = ?, os_serial = ?, ip_address = ?, mac_address = ?, cpu = ?, memory_mb = ?, disks_json = ?, software_json = ?, hardware_json = ?, peripherals_json = ?, domain_username = ?, antivirus = ?, source = "server_inventory", last_inventory_at = NOW() WHERE id = ?')->execute([...$values, $assetId]);
    } else {
        db()->prepare('INSERT INTO assets (asset_tag, hostname, serial_number, manufacturer, model, operating_system, os_architecture, os_serial, ip_address, mac_address, cpu, memory_mb, disks_json, software_json, hardware_json, peripherals_json, domain_username, antivirus, source, last_inventory_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "server_inventory", NOW())')->execute([$assetTag, ...$values]);
        $assetId = (int) db()->lastInsertId();
    }
    sync_inventory_rows($assetId, $inventory, 'server_inventory');
    if (inventory_asset_exists($assetId)) {
        db()->prepare('INSERT INTO asset_inventory_history (asset_id, snapshot_json, collected_by) VALUES (?, ?, ?)')->execute([$assetId, $payload, $userId > 0 ? $userId : null]);
    }
    return $assetId;
}

function inventory_collect_initial_asset(int $userId): ?int
{
    try {
        $inventory = collect_server_inventory();
        $assetTag = 'SERVER-' . strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($inventory['hostname'] ?? 'HOST')));
        $query = db()->prepare('SELECT id FROM assets WHERE asset_tag = ? LIMIT 1');
        $query->execute([$assetTag]);
        if ($query->fetchColumn()) {
            return null;
        }
        return inventory_upsert_server_asset($inventory, $userId);
    } catch (Throwable) {
        return null;
    }
}

/**
 * نام سیستم کلاینت از روی IP — با کش یک‌هفته‌ای.
 *
 * ⚠️ چرا: gethostbyaddr روی هر درخواست ورود، تا چند ثانیه (timeout دی‌ان‌اس) بلاک
 * می‌شد و بارگذاری صفحهٔ ورود را کند می‌کرد. حالا نتیجه در storage ذخیره می‌شود و
 * برای هر IP حداکثر هفته‌ای یک‌بار پرس‌وجو انجام می‌شود. با تنظیم
 * client_hostname_lookup=0 می‌توان این پرس‌وجو را کامل خاموش کرد.
 */
function inventory_reverse_name(string $ip, string $fallback): string
{
    static $cache = null;
    $enabled = true;
    try {
        $enabled = setting('client_hostname_lookup', '1') !== '0';
    } catch (Throwable) {
    }
    if (!$enabled) {
        return $fallback;
    }
    if ($cache === null) {
        $cache = [];
        $root = defined('APP_ROOT') ? (string) APP_ROOT : dirname(__DIR__);
        $file = $root . '/storage/hostname_cache.json';
        if (is_file($file)) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded)) {
                $cache = $decoded;
            }
        }
    }
    $now = time();
    $ttl = 7 * 86400;
    $entry = $cache[$ip] ?? null;
    if (is_array($entry) && ($now - (int) ($entry['ts'] ?? 0)) < $ttl) {
        $name = trim((string) ($entry['name'] ?? ''));
        return $name !== '' ? $name : $fallback;
    }
    $resolved = @gethostbyaddr($ip);
    $name = (is_string($resolved) && $resolved !== '' && $resolved !== $ip) ? $resolved : '';
    $cache[$ip] = ['name' => $name, 'ts' => $now];
    // فقط همین یک کلید جدید و آیتم‌های تازه نگه داشته می‌شوند (فایل کوچک می‌ماند)
    if (count($cache) > 800) {
        uasort($cache, static fn (array $a, array $b): int => (int) ($b['ts'] ?? 0) <=> (int) ($a['ts'] ?? 0));
        $cache = array_slice($cache, 0, 800, true);
    }
    $root = defined('APP_ROOT') ? (string) APP_ROOT : dirname(__DIR__);
    $dir = $root . '/storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    @file_put_contents($dir . '/hostname_cache.json', (string) json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $name !== '' ? $name : $fallback;
}

function inventory_collect_client_asset(array $user): ?int
{
    try {
        $clientIp = inventory_client_ip();
        if ($clientIp === '' || !filter_var($clientIp, FILTER_VALIDATE_IP)) {
            return null;
        }
        $serverAddr = (string) ($_SERVER['SERVER_ADDR'] ?? '');
        if (in_array($clientIp, ['127.0.0.1', '::1'], true) || ($serverAddr !== '' && $clientIp === $serverAddr)) {
            return null;
        }
        $hostname = inventory_reverse_name($clientIp, 'CLIENT-' . str_replace(['.', ':'], '-', $clientIp));
        $hostname = substr(trim($hostname), 0, 190);
        $assetTag = 'CLIENT-' . strtoupper(substr(hash('sha256', $clientIp), 0, 16));
        $username = trim((string) ($user['username'] ?? ''));
        $departmentId = (int) ($user['department_id'] ?? 0) ?: null;
        $hardware = json_encode([
            'collection_mode' => 'client_request_identity',
            'remote_addr' => $clientIp,
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'collected_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $query = db()->prepare('SELECT id FROM assets WHERE asset_tag = ? LIMIT 1');
        $query->execute([$assetTag]);
        $assetId = (int) ($query->fetchColumn() ?: 0);
        $hasFullHardware = false;
        if ($assetId > 0) {
            $existing = db()->prepare('SELECT hardware_json FROM assets WHERE id = ? LIMIT 1');
            $existing->execute([$assetId]);
            $existingHardware = json_decode((string) $existing->fetchColumn(), true);
            $hasFullHardware = is_array($existingHardware) && ($existingHardware['collection_mode'] ?? '') !== 'client_request_identity' && !empty($existingHardware);
            if ($hasFullHardware) {
                db()->prepare('UPDATE assets SET hostname = ?, owner_user_id = ?, department_id = COALESCE(?, department_id), ip_address = ?, domain_username = ?, source = "agent", last_inventory_at = NOW() WHERE id = ?')->execute([$hostname, (int) ($user['id'] ?? 0), $departmentId, $clientIp, $username, $assetId]);
            } else {
                db()->prepare('UPDATE assets SET hostname = ?, owner_user_id = ?, department_id = COALESCE(?, department_id), ip_address = ?, domain_username = ?, hardware_json = ?, source = "agent", last_inventory_at = NOW() WHERE id = ?')->execute([$hostname, (int) ($user['id'] ?? 0), $departmentId, $clientIp, $username, $hardware, $assetId]);
            }
        } else {
            db()->prepare('INSERT INTO assets (asset_tag, hostname, computer_type, owner_user_id, department_id, ip_address, domain_username, hardware_json, source, last_inventory_at) VALUES (?, ?, "Windows client", ?, ?, ?, ?, ?, "agent", NOW())')->execute([$assetTag, $hostname, (int) ($user['id'] ?? 0), $departmentId, $clientIp, $username, $hardware]);
            $assetId = (int) db()->lastInsertId();
        }
        // شناسنامه هم پر شود: قبلاً فقط جدول assets به‌روز می‌شد و فرم «شناسنامه سیستم»
        // برای سیستم کاربران خالی می‌ماند (شکایت «هیچ اطلاعاتی استخراج نمی‌شود»).
        if (function_exists('asset_profile_apply_auto')) {
            asset_profile_apply_auto($assetId, array_filter([
                'hostname' => $hostname,
                'ip_address' => $clientIp,
                'user_login' => $username,
                'computer_type' => 'Windows client',
                'connected_network' => 1,
            ], static fn ($v): bool => trim((string) $v) !== ''), (int) ($user['id'] ?? 0), false);
        }
        if (!$hasFullHardware) {
            $historyExists = db()->prepare('SELECT 1 FROM asset_inventory_history WHERE asset_id = ? LIMIT 1');
            $historyExists->execute([$assetId]);
            if (!$historyExists->fetchColumn()) {
                db()->prepare('INSERT INTO asset_inventory_history (asset_id, snapshot_json, collected_by) VALUES (?, ?, ?)')->execute([$assetId, $hardware ?: '{}', (int) ($user['id'] ?? 0)]);
            }
        }
        return $assetId > 0 ? $assetId : null;
    } catch (Throwable $exception) {
        error_log('ITSM client identity inventory failed: ' . $exception->getMessage());
        return null;
    }
}

function inventory_client_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

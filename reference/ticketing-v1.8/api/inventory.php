<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/inventory.php';
require dirname(__DIR__) . '/asset-profile.php';

header('Content-Type: application/json; charset=utf-8');

function inventory_api_response(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function inventory_api_text(mixed $value, int $maxLength = 255): string
{
    $value = trim((string) $value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
}

function inventory_api_json(mixed $value): string
{
    $json = json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? '[]' : $json;
}

function inventory_api_asset_tag(array $payload): string
{
    $provided = inventory_api_text($payload['asset_tag'] ?? '', 100);
    if ($provided !== '') {
        $provided = strtoupper((string) preg_replace('/[^A-Za-z0-9_-]/', '-', $provided));
        if ($provided !== '') {
            return $provided;
        }
    }
    $hostname = inventory_api_text($payload['hostname'] ?? 'UNKNOWN', 60);
    $hostname = strtoupper((string) preg_replace('/[^A-Za-z0-9_-]/', '-', $hostname));
    $fingerprint = implode('|', [
        inventory_api_text($payload['serial_number'] ?? ''),
        inventory_api_text($payload['mac_address'] ?? ''),
        $hostname,
    ]);
    return substr('CLIENT-' . ($hostname !== '' ? $hostname : 'UNKNOWN') . '-' . strtoupper(substr(hash('sha256', $fingerprint), 0, 10)), 0, 100);
}

function inventory_api_manual_fields(array $existing): array
{
    $manual = is_array($existing['manual_entries'] ?? null) ? $existing['manual_entries'] : [];
    if ($manual !== []) {
        return $manual;
    }
    foreach (['printers', 'scanners', 'monitors', 'case', 'mouse', 'keyboard'] as $key) {
        if (array_key_exists($key, $existing)) {
            $manual[$key] = $existing[$key];
        }
    }
    return $manual;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    inventory_api_response(405, ['ok' => false, 'error' => 'فقط درخواست POST مجاز است.']);
}

$expectedToken = (string) cfg('security.inventory_token', '');
$providedToken = (string) ($_SERVER['HTTP_X_INVENTORY_TOKEN'] ?? '');
if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    inventory_api_response(401, ['ok' => false, 'error' => 'توکن Inventory معتبر نیست.']);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 5 * 1024 * 1024) {
    inventory_api_response(413, ['ok' => false, 'error' => 'حجم اطلاعات Inventory بیش از حد مجاز است.']);
}

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) {
    inventory_api_response(400, ['ok' => false, 'error' => 'بدنه JSON معتبر نیست.']);
}

$hostname = inventory_api_text($payload['hostname'] ?? '', 190);
if ($hostname === '') {
    inventory_api_response(422, ['ok' => false, 'error' => 'نام سیستم الزامی است.']);
}

$assetTag = inventory_api_asset_tag($payload);
$domainUsername = inventory_api_text($payload['domain_username'] ?? '', 190);
$ownerId = null;
$departmentId = null;
if ($domainUsername !== '') {
    $ownerQuery = db()->prepare('SELECT id, department_id FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $ownerQuery->execute([$domainUsername]);
    $owner = $ownerQuery->fetch();
    if ($owner) {
        $ownerId = (int) $owner['id'];
        $departmentId = $owner['department_id'] !== null ? (int) $owner['department_id'] : null;
    }
}

$peripherals = is_array($payload['peripherals'] ?? null) ? $payload['peripherals'] : [];
$hardware = is_array($payload['hardware'] ?? null) ? $payload['hardware'] : [];
$operatingSystem = is_array($hardware['operating_system'] ?? null) ? $hardware['operating_system'] : [];
$manufacturer = inventory_api_text($payload['manufacturer'] ?? ($hardware['manufacturer'] ?? ''), 190);
$model = inventory_api_text($payload['model'] ?? ($hardware['model'] ?? ''), 190);
$osArchitecture = inventory_api_text($payload['os_architecture'] ?? ($operatingSystem['OSArchitecture'] ?? ''), 40);
$osSerial = inventory_api_text($payload['os_serial'] ?? '', 190);
$existingId = 0;
$existingPeripherals = [];
$assetQuery = db()->prepare('SELECT id, peripherals_json FROM assets WHERE asset_tag = ? LIMIT 1');
$assetQuery->execute([$assetTag]);
$existing = $assetQuery->fetch();
if ($existing) {
    $existingId = (int) $existing['id'];
    $existingPeripherals = json_decode((string) $existing['peripherals_json'], true) ?: [];
}
$manual = inventory_api_manual_fields($existingPeripherals);
if ($manual !== []) {
    $peripherals['manual_entries'] = $manual;
}

$values = [
    $hostname,
    inventory_api_text($payload['serial_number'] ?? '', 190),
    inventory_api_text($payload['computer_type'] ?? '', 100),
    $manufacturer,
    $model,
    $ownerId,
    $departmentId,
    inventory_api_text($payload['operating_system'] ?? '', 255),
    $osArchitecture,
    $osSerial,
    inventory_api_text($payload['ip_address'] ?? '', 100),
    inventory_api_text($payload['mac_address'] ?? '', 100),
    inventory_api_text($payload['cpu'] ?? '', 255),
    max(0, (int) ($payload['memory_mb'] ?? 0)),
    inventory_api_json($payload['disks'] ?? []),
    inventory_api_json($payload['software'] ?? []),
    inventory_api_json($hardware),
    inventory_api_json($peripherals),
    $domainUsername,
    inventory_api_text($payload['antivirus'] ?? '', 255),
];

/** @var PDO|null $pdo */
$pdo = null;
try {
    $pdo = db();
    asset_profile_ensure_schema();
    $pdo->beginTransaction();
    if ($existingId > 0) {
        db()->prepare('UPDATE assets SET hostname = ?, serial_number = ?, computer_type = ?, manufacturer = ?, model = ?, owner_user_id = COALESCE(?, owner_user_id), department_id = COALESCE(?, department_id), operating_system = ?, os_architecture = ?, os_serial = ?, ip_address = ?, mac_address = ?, cpu = ?, memory_mb = ?, disks_json = ?, software_json = ?, hardware_json = ?, peripherals_json = ?, domain_username = ?, antivirus = ?, source = "agent", last_inventory_at = NOW() WHERE id = ?')->execute([...$values, $existingId]);
        $assetId = $existingId;
    } else {
        db()->prepare('INSERT INTO assets (asset_tag, hostname, serial_number, computer_type, manufacturer, model, owner_user_id, department_id, operating_system, os_architecture, os_serial, ip_address, mac_address, cpu, memory_mb, disks_json, software_json, hardware_json, peripherals_json, domain_username, antivirus, source, last_inventory_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "agent", NOW())')->execute([$assetTag, ...$values]);
        $assetId = (int) db()->lastInsertId();
    }
    sync_inventory_rows($assetId, $payload, 'agent');
    asset_profile_apply_auto($assetId, asset_profile_auto_values($payload), 0, false);
    $snapshot = $payload;
    $snapshot['asset_tag'] = $assetTag;
    $snapshot['received_at'] = date('c');
    db()->prepare('INSERT INTO asset_inventory_history (asset_id, snapshot_json, collected_by) VALUES (?, ?, NULL)')->execute([$assetId, inventory_api_json($snapshot)]);
    activity_log(['action_code' => 'agent_inventory_collected', 'module' => 'api', 'target_type' => 'asset', 'target_id' => $assetId, 'target_label' => $assetTag, 'meta' => ['hostname' => $hostname]]);
    record_asset_history($assetId, 'inventory_collected', 'برداشت خودکار Inventory کلاینت', null, null, 'اطلاعات Agent کلاینت به‌روزرسانی شد.');
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    inventory_api_response(500, ['ok' => false, 'error' => 'ذخیره Inventory انجام نشد.']);
}

inventory_api_response(200, [
    'ok' => true,
    'asset_id' => $assetId,
    'asset_tag' => $assetTag,
    'owner_matched' => $ownerId !== null,
]);

<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

$sourceRoot = APP_ROOT . '/assets/uploads';
$targetRoot = APP_ROOT . '/storage/uploads';
if (!is_dir($targetRoot) && !mkdir($targetRoot, 0750, true) && !is_dir($targetRoot)) {
    throw new RuntimeException('پوشه مقصد پیوست‌ها قابل ایجاد نیست.');
}

$query = db()->query('SELECT stored_name FROM ticket_attachments');
$migrated = 0;
$missing = 0;
foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $storedName) {
    $storedName = basename((string) $storedName);
    if ($storedName === '') {
        continue;
    }
    $source = $sourceRoot . DIRECTORY_SEPARATOR . $storedName;
    $target = $targetRoot . DIRECTORY_SEPARATOR . $storedName;
    if (is_file($target)) {
        continue;
    }
    if (!is_file($source)) {
        $missing++;
        continue;
    }
    if (!rename($source, $target)) {
        throw new RuntimeException('انتقال پیوست انجام نشد: ' . $storedName);
    }
    @chmod($target, 0640);
    $migrated++;
}

fwrite(STDOUT, "پیوست‌های منتقل‌شده: {$migrated}\nپیوست‌های پیدا نشده: {$missing}\n");

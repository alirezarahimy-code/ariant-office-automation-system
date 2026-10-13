<?php
declare(strict_types=1);

function backup_root_path(): string
{
    $configured = trim((string) (getenv('ITSM_BACKUP_PATH') ?: ''));
    return $configured !== '' ? $configured : APP_ROOT . '/storage/backups';
}

function backup_mysql_binary(string $tool): ?string
{
    $envKey = $tool === 'mysqldump' ? 'ITSM_MYSQLDUMP_PATH' : 'ITSM_MYSQL_PATH';
    $configured = trim((string) (getenv($envKey) ?: ''));
    $xamppRoot = dirname(APP_ROOT, 2);
    $candidates = array_filter([
        $configured,
        $xamppRoot . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $tool . '.exe',
        $xamppRoot . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $tool,
        '/usr/bin/' . $tool,
        '/usr/local/bin/' . $tool,
    ]);
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && (is_executable($candidate) || PHP_OS_FAMILY === 'Windows')) {
            return $candidate;
        }
    }
    return null;
}

function backup_run_process(string $binary, array $arguments, ?string $inputPath = null, ?string $outputPath = null): void
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('proc_open در PHP فعال نیست؛ عملیات MySQL از صفحه وب قابل اجرا نیست.');
    }
    $command = escapeshellarg($binary);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg((string) $argument);
    }
    $descriptors = [
        0 => $inputPath ? ['file', $inputPath, 'rb'] : ['pipe', 'r'],
        1 => $outputPath ? ['file', $outputPath, 'wb'] : ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $environment = $_ENV;
    $environment['MYSQL_PWD'] = (string) cfg('database.password', '');
    if (empty($environment['SystemRoot']) && getenv('SystemRoot')) {
        $environment['SystemRoot'] = getenv('SystemRoot');
    }
    if (empty($environment['SystemRoot']) && PHP_OS_FAMILY === 'Windows') {
        $environment['SystemRoot'] = 'C:\\Windows';
    }
    if (empty($environment['PATH']) && getenv('PATH')) {
        $environment['PATH'] = getenv('PATH');
    }
    $process = proc_open($command, $descriptors, $pipes, APP_ROOT, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('اجرای ابزار MySQL ممکن نیست.');
    }
    if (!$inputPath && isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    $error = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
    if (isset($pipes[2]) && is_resource($pipes[2])) {
        fclose($pipes[2]);
    }
    if (isset($pipes[1]) && is_resource($pipes[1])) {
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(trim((string) $error) ?: 'عملیات MySQL ناموفق بود.');
    }
}

function backup_uploads(string $destination): int
{
    if (!class_exists('ZipArchive')) {
        return 0;
    }
    $uploadsRoot = APP_ROOT . '/storage/uploads';
    $archive = new ZipArchive();
    if ($archive->open($destination . '/uploads.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ساخت آرشیو پیوست‌ها انجام نشد.');
    }
    $count = 0;
    if (is_dir($uploadsRoot)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($uploadsRoot))), '/');
            $archive->addFile($file->getPathname(), 'uploads/' . $relative);
            $count++;
        }
    }
    $archive->close();
    return $count;
}

function create_backup_bundle(): array
{
    $dumpBinary = backup_mysql_binary('mysqldump');
    if ($dumpBinary === null) {
        throw new RuntimeException('mysqldump پیدا نشد. مسیر ITSM_MYSQLDUMP_PATH یا XAMPP را بررسی کنید.');
    }
    $root = backup_root_path();
    if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
        throw new RuntimeException('پوشه پشتیبان‌گیری قابل ایجاد نیست.');
    }
    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $destination = $root . DIRECTORY_SEPARATOR . $name;
    if (!mkdir($destination, 0750, true)) {
        throw new RuntimeException('پوشه نسخه پشتیبان ساخته نشد.');
    }
    $dbName = (string) cfg('database.name', 'persian_ticketing');
    $dumpPath = $destination . DIRECTORY_SEPARATOR . $dbName . '.sql';
    backup_run_process($dumpBinary, [
        '--single-transaction', '--routines', '--events', '--triggers',
        '--host=' . (string) cfg('database.host', '127.0.0.1'),
        '--port=' . (int) cfg('database.port', 3306),
        '--user=' . (string) cfg('database.user', ''),
        $dbName,
    ], null, $dumpPath);
    $uploadCount = backup_uploads($destination);
    file_put_contents($destination . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
        'created_at' => date('c'),
        'database' => $dbName,
        'uploads' => $uploadCount,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return ['name' => $name, 'path' => $destination, 'uploads' => $uploadCount];
}

function backup_runs(): array
{
    $root = backup_root_path();
    if (!is_dir($root)) {
        return [];
    }
    $runs = [];
    foreach (scandir($root) ?: [] as $name) {
        $path = $root . DIRECTORY_SEPARATOR . $name;
        if ($name === '.' || $name === '..' || !is_dir($path) || !is_file($path . DIRECTORY_SEPARATOR . 'manifest.json')) {
            continue;
        }
        $runs[] = ['name' => $name, 'created_at' => date('Y-m-d H:i:s', (int) filemtime($path)), 'size' => backup_directory_size($path)];
    }
    usort($runs, static fn (array $left, array $right): int => strcmp($right['name'], $left['name']));
    return array_slice($runs, 0, 30);
}

function backup_directory_size(string $path): int
{
    $size = 0;
    if (!is_dir($path)) {
        return 0;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}

function backup_download_path(string $relative): ?string
{
    $root = realpath(backup_root_path());
    $relative = ltrim(str_replace(['\\', '..'], ['/', ''], $relative), '/');
    $path = $root ? realpath($root . DIRECTORY_SEPARATOR . $relative) : false;
    $prefix = $root ? rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';
    return $path && $root && str_starts_with($path, $prefix) && is_file($path) ? $path : null;
}

function restore_upload_archive(array $file): int
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !class_exists('ZipArchive')) {
        throw new RuntimeException('آرشیو پیوست معتبر نیست یا افزونه ZIP فعال نیست.');
    }
    $maxBytes = (int) (getenv('ITSM_MAX_RESTORE_MB') ?: 256) * 1024 * 1024;
    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $maxBytes) {
        throw new RuntimeException('حجم آرشیو پیوست برای بازیابی مجاز نیست.');
    }
    $archive = new ZipArchive();
    if ($archive->open((string) $file['tmp_name']) !== true) {
        throw new RuntimeException('بازکردن آرشیو پیوست انجام نشد.');
    }
    $uploadsRoot = APP_ROOT . '/storage/uploads';
    if (!is_dir($uploadsRoot) && !mkdir($uploadsRoot, 0750, true) && !is_dir($uploadsRoot)) {
        throw new RuntimeException('پوشه پیوست‌ها قابل ایجاد نیست.');
    }
    $count = 0;
    for ($index = 0; $index < $archive->numFiles; $index++) {
        $name = str_replace('\\', '/', (string) $archive->getNameIndex($index));
        $name = preg_replace('#^uploads/#', '', $name) ?? $name;
        if ($name === '' || str_ends_with($name, '/') || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
            continue;
        }
        $target = $uploadsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('ساخت مسیر پیوست انجام نشد.');
        }
        $input = $archive->getStream($index);
        $output = fopen($target, 'wb');
        if (!is_resource($input) || !is_resource($output)) {
            throw new RuntimeException('بازیابی یکی از پیوست‌ها انجام نشد.');
        }
        stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
        $count++;
    }
    $archive->close();
    return $count;
}

function restore_sql_dump(array $file): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'sql') {
        throw new RuntimeException('فایل بازیابی باید یک فایل SQL معتبر باشد.');
    }
    $maxBytes = (int) (getenv('ITSM_MAX_RESTORE_MB') ?: 256) * 1024 * 1024;
    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $maxBytes) {
        throw new RuntimeException('حجم فایل SQL برای بازیابی مجاز نیست.');
    }
    $mysqlBinary = backup_mysql_binary('mysql');
    if ($mysqlBinary === null) {
        throw new RuntimeException('mysql پیدا نشد. مسیر ITSM_MYSQL_PATH یا XAMPP را بررسی کنید.');
    }
    backup_run_process($mysqlBinary, [
        '--host=' . (string) cfg('database.host', '127.0.0.1'),
        '--port=' . (int) cfg('database.port', 3306),
        '--user=' . (string) cfg('database.user', ''),
        (string) cfg('database.name', 'persian_ticketing'),
    ], (string) $file['tmp_name']);
}

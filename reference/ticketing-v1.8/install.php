<?php
declare(strict_types=1);

session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Lax',
]);
session_start();
$configFile = (string) (getenv('ITSM_CONFIG_PATH') ?: __DIR__ . '/config.php');
$lockFile = (string) (getenv('ITSM_INSTALL_LOCK_PATH') ?: __DIR__ . '/installed.lock');
$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
if (!$isLocalRequest) {
    http_response_code(403);
    exit('اجرای نصب‌گر فقط از روی خود سرور و با localhost مجاز است.');
}
$forceInstall = isset($_GET['force']) && (string) $_GET['force'] === '1';
if ((is_file($configFile) || is_file($lockFile)) && !$forceInstall) {
    http_response_code(403);
    exit('نصب قبلاً آغاز یا تکمیل شده است. نصب‌گر را از دسترس خارج کنید.');
}
if (isset($_GET['force']) && !$forceInstall) {
    http_response_code(403);
    exit('پارامتر بازبینی نصب معتبر نیست.');
}

function ih(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function install_csrf(): string
{
    if (empty($_SESSION['install_csrf'])) {
        $_SESSION['install_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['install_csrf'];
}

function install_layout(string $title, string $body, int $step): void
{
    $steps = ['۱. اطلاعات سامانه', '۲. اتصال دامین', '۳. مدیر اولیه'];
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . ih($title) . '</title><link rel="stylesheet" href="assets/style.css"></head><body class="install-body"><main class="install-shell"><div class="brand-lockup"><div class="brand-mark">پ</div><div><strong>نصب سامانه</strong><small>راه‌اندازی امن و مرحله‌ای</small></div></div><div class="install-steps">';
    foreach ($steps as $index => $label) {
        $class = ($index + 1 === $step ? ' active' : ($index + 1 < $step ? ' done' : ''));
        echo '<span class="install-step' . $class . '"><b>' . ($index + 1) . '</b>' . $label . '</span>';
    }
    echo '</div><section class="card install-card"><h1>' . ih($title) . '</h1>' . $body . '</section><p class="install-foot">سامانه تیکتینگ فارسی • مناسب اجرا روی XAMPP</p></main></body></html>';
    exit;
}

function install_error(string $message): void
{
    $_SESSION['install_error'] = $message;
}

function take_install_error(): string
{
    $error = (string) ($_SESSION['install_error'] ?? '');
    unset($_SESSION['install_error']);
    return $error;
}

function mysql_from_session(): PDO
{
    $db = $_SESSION['install']['database'];
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']);
    return new PDO($dsn, $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function ldap_probe(array $ldap): array
{
    if (!function_exists('ldap_connect')) {
        return [false, 'افزونه LDAP در PHP فعال نیست. برای اتصال به دامین، فایل php.ini را باز کنید و extension=ldap را فعال کنید.'];
    }
    if ($ldap['host'] === '' || $ldap['base_dn'] === '') {
        return [false, 'آدرس کنترلر و Base DN را وارد کنید.'];
    }
    $scheme = $ldap['ssl'] ? 'ldaps://' : 'ldap://';
    $connection = @ldap_connect($scheme . $ldap['host'], $ldap['port']);
    if (!$connection) {
        return [false, 'اتصال به کنترلر دامین برقرار نشد.'];
    }
    ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
    if (!@ldap_bind($connection, $ldap['bind_dn'], $ldap['bind_password'])) {
        return [false, 'اتصال برقرار شد اما Bind با حساب سرویس موفق نبود.'];
    }
    return [true, 'اتصال به کنترلر دامین و Bind با موفقیت انجام شد.'];
}

$step = max(1, min(3, (int) ($_POST['step'] ?? $_GET['step'] ?? 1)));
$install = $_SESSION['install'] ?? [];
$error = take_install_error();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) ($_SESSION['install_csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        install_layout('خطای امنیتی', '<p class="alert danger">درخواست منقضی شده است. صفحه را دوباره باز کنید.</p>', $step);
    }
    $action = (string) ($_POST['action'] ?? 'next');
    if ($step === 1) {
        $appName = trim((string) ($_POST['app_name'] ?? ''));
        $host = trim((string) ($_POST['db_host'] ?? '127.0.0.1'));
        $port = (int) ($_POST['db_port'] ?? 3306);
        $name = trim((string) ($_POST['db_name'] ?? 'persian_ticketing'));
        $user = trim((string) ($_POST['db_user'] ?? 'root'));
        $password = (string) ($_POST['db_password'] ?? '');
        $dropExisting = !empty($_POST['db_drop_existing']);
        $dropConfirmation = trim((string) ($_POST['db_drop_confirm'] ?? ''));
        $reservedDatabases = ['mysql', 'information_schema', 'performance_schema', 'sys'];
        if ($appName === '' || $host === '' || !preg_match('/^[A-Za-z0-9.:-]{1,255}$/', $host)
            || $port < 1 || $port > 65535 || $user === '' || strlen($user) > 190
            || $name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)
            || in_array(strtolower($name), $reservedDatabases, true)
            || ($dropExisting && !hash_equals($name, $dropConfirmation))) {
            $error = 'اطلاعات سامانه یا اتصال MySQL معتبر نیست. برای حذف پایگاه موجود، نام آن را دقیقاً در کادر تأیید وارد کنید.';
        } else {
            $_SESSION['install']['app'] = ['name' => $appName, 'logo' => ''];
            $_SESSION['install']['database'] = ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'password' => $password, 'charset' => 'utf8mb4', 'drop_existing' => $dropExisting];
            if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
                $mime = mime_content_type($_FILES['logo']['tmp_name']) ?: '';
                    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
                if (isset($allowed[$mime]) && (int) $_FILES['logo']['size'] <= 2 * 1024 * 1024) {
                    @mkdir(__DIR__ . '/assets/uploads', 0755, true);
                    $filename = 'logo-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                    move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__ . '/assets/uploads/' . $filename);
                    $_SESSION['install']['app']['logo'] = 'assets/uploads/' . $filename;
                }
            }
            if ($action === 'test_db') {
                try {
                    mysql_from_session();
                    $error = 'اتصال به MySQL برقرار شد.';
                } catch (Throwable $exception) {
                    $error = 'اتصال MySQL ناموفق بود: ' . $exception->getMessage();
                }
            } else {
                $step = 2;
            }
        }
    } elseif ($step === 2) {
        $ldap = [
            'enabled' => !empty($_POST['ldap_enabled']),
            'host' => trim((string) ($_POST['ldap_host'] ?? '')),
            'port' => (int) ($_POST['ldap_port'] ?? 389),
            'ssl' => !empty($_POST['ldap_ssl']),
            'base_dn' => trim((string) ($_POST['ldap_base_dn'] ?? '')),
            'bind_dn' => trim((string) ($_POST['ldap_bind_dn'] ?? '')),
            'bind_password' => (string) ($_POST['ldap_bind_password'] ?? ''),
            'domain_suffix' => trim((string) ($_POST['domain_suffix'] ?? '')),
            'user_filter' => '(&(objectClass=user)(sAMAccountName=%s))',
            'default_role' => 'user',
        ];
        $_SESSION['install']['ldap'] = $ldap;
        if ($action === 'test_ldap') {
            [$ok, $message] = ldap_probe($ldap);
            $error = ($ok ? '✓ ' : '✕ ') . $message;
        } else {
            $step = 3;
        }
    } else {
        $username = trim((string) ($_POST['admin_username'] ?? 'admin'));
        $fullName = trim((string) ($_POST['admin_full_name'] ?? 'مدیر سامانه'));
        $password = (string) ($_POST['admin_password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username) || strlen($password) < 8 || $fullName === '') {
            $error = 'نام کاربری معتبر و رمز عبور حداقل ۸ کاراکتری وارد کنید.';
        } else {
            try {
                $db = $_SESSION['install']['database'];
                 $server = mysql_from_session();
                 if (!empty($db['drop_existing'])) {
                     $server->exec('DROP DATABASE IF EXISTS `' . $db['name'] . '`');
                 }
                 $server->exec('CREATE DATABASE IF NOT EXISTS `' . $db['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                 $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']), $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                 $configWritten = false;
                  $lockWritten = false;
                  try {
                 if ($pdo->query('SHOW TABLES')->fetchColumn() !== false) {
                     throw new RuntimeException('پایگاه‌داده انتخاب‌شده خالی نیست. برای نصب تمیز، پایگاه‌داده جدید و خالی انتخاب کنید؛ حذف پایگاه موجود فقط پس از تهیه نسخه پشتیبان مجاز است.');
                 }
                 $schema = file_get_contents(__DIR__ . '/schema.sql');
                foreach (preg_split('/;\s*(?:\r?\n|$)/', (string) $schema) as $statement) {
                    if (trim($statement) !== '') {
                        try {
                            $pdo->exec($statement);
                        } catch (PDOException $exception) {
                            $isManagerForeignKey = str_contains($statement, 'ALTER TABLE departments ADD CONSTRAINT fk_departments_manager');
                            if (!$isManagerForeignKey || !str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                                throw $exception;
                            }
                        }
                     }
                 }
                 // MySQL implicitly commits DDL, so start the transaction after schema creation.
                 $pdo->beginTransaction();
                 $settings = [
                    'app_name' => $_SESSION['install']['app']['name'],
                    'app_logo' => $_SESSION['install']['app']['logo'],
                    'ldap_enabled' => $_SESSION['install']['ldap']['enabled'] ? '1' : '0',
                    'ldap_host' => $_SESSION['install']['ldap']['host'],
                    'ldap_port' => (string) $_SESSION['install']['ldap']['port'],
                    'ldap_ssl' => $_SESSION['install']['ldap']['ssl'] ? '1' : '0',
                    'ldap_base_dn' => $_SESSION['install']['ldap']['base_dn'],
                    'ldap_bind_dn' => $_SESSION['install']['ldap']['bind_dn'],
                    'ldap_bind_password' => $_SESSION['install']['ldap']['bind_password'],
                    'ldap_domain_suffix' => $_SESSION['install']['ldap']['domain_suffix'],
                ];
                 $settingQuery = $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
                 foreach ($settings as $key => $value) {
                     $settingQuery->execute([$key, (string) $value]);
                 }
                 $existingUserQuery = $pdo->prepare('SELECT auth_source FROM users WHERE username = ? LIMIT 1');
                 $existingUserQuery->execute([$username]);
                 $existingUser = $existingUserQuery->fetchColumn();
                 if ($existingUser !== false && $existingUser !== 'local') {
                     throw new RuntimeException('نام کاربری مدیر با یک حساب دامینی موجود تداخل دارد. نام دیگری انتخاب کنید.');
                 }
                 $userQuery = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, is_primary_admin, auth_source) VALUES (?, ?, ?, "primary_admin", 1, "local") ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), full_name = VALUES(full_name), role = "primary_admin", is_primary_admin = 1, auth_source = "local", is_active = 1');
                 $userQuery->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName]);
                 $adminIdQuery = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                 $adminIdQuery->execute([$username]);
                 $adminId = (int) $adminIdQuery->fetchColumn();
                 if ($adminId <= 0) {
                     throw new RuntimeException('ساخت مدیر اولیه انجام نشد.');
                 }
                  $categoryQuery = $pdo->prepare('INSERT INTO categories (name, code, service_group) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), service_group = VALUES(service_group), is_active = 1');
                 foreach ([
                     ['سخت‌افزار', 'IT-CAT-HW', 'it'],
                     ['نرم‌افزار', 'IT-CAT-SW', 'it'],
                     ['شبکه و اینترنت', 'IT-CAT-NET', 'it'],
                     ['حساب کاربری و دسترسی', 'IT-CAT-ACC', 'it'],
                     ['چاپ و اسکن', 'IT-CAT-PRINT', 'it'],
                     ['تجهیزات جانبی', 'IT-CAT-PERIPH', 'it'],
                     ['پشتیبان‌گیری و بازیابی', 'IT-CAT-BACKUP', 'it'],
                     ['نصب و راه‌اندازی', 'IT-CAT-SETUP', 'it'],
                     ['سایر خدمات فناوری اطلاعات', 'IT-CAT-MISC', 'it'],
                     ['تجهیزات و فضای اداری', 'SUP-CAT-OFFICE', 'support'],
                     ['تأسیسات و انرژی', 'SUP-CAT-UTIL', 'support'],
                     ['اثاثیه', 'SUP-CAT-FURN', 'support'],
                     ['خدمات و نظافت', 'SUP-CAT-CLEAN', 'support'],
                     ['ملزومات اداری', 'SUP-CAT-STAT', 'support'],
                     ['خدمات عمومی', 'SUP-CAT-GEN', 'support'],
                     ['سایر خدمات پشتیبانی', 'SUP-CAT-MISC', 'support'],
                 ] as $category) {
                     $categoryQuery->execute($category);
                 }
                 $categoryIds = $pdo->query('SELECT code, id FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR);
                  $departmentQuery = $pdo->prepare('INSERT IGNORE INTO departments (name, code) VALUES (?, ?)');
                 foreach ([['فناوری اطلاعات', 'IT'], ['منابع انسانی', 'HR'], ['مالی', 'FIN'], ['اداری', 'ADM']] as $department) {
                     $departmentQuery->execute($department);
                 }
                  $unitQuery = $pdo->prepare('INSERT INTO handling_units (code, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1');
                 $unitQuery->execute(['it', 'واحد فناوری اطلاعات']);
                 $unitQuery->execute(['support', 'واحد پشتیبانی']);
                 $unitIds = $pdo->query('SELECT code, id FROM handling_units')->fetchAll(PDO::FETCH_KEY_PAIR);
                  $serviceQuery = $pdo->prepare('INSERT INTO service_catalog (name, code, description, service_group, default_priority, handling_unit_id, requires_asset, default_ticket_type, category_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), service_group = VALUES(service_group), default_priority = VALUES(default_priority), handling_unit_id = VALUES(handling_unit_id), requires_asset = VALUES(requires_asset), default_ticket_type = VALUES(default_ticket_type), category_id = VALUES(category_id), created_by = VALUES(created_by)');
                 foreach ([
                     ['خرابی یا کندی رایانه', 'IT-COMPUTER', 'کندی، هنگ کردن یا خطای عملکرد رایانه.', 'it', 'urgent', $unitIds['it'], 1, 'incident', 'IT-CAT-HW'],
                     ['رایانه روشن نمی‌شود', 'IT-BOOT', 'عدم روشن شدن یا بوت نشدن سیستم.', 'it', 'urgent', $unitIds['it'], 1, 'incident', 'IT-CAT-HW'],
                     ['صفحه آبی یا ری‌استارت مکرر', 'IT-BLUE', 'خطای صفحه آبی، ری‌استارت یا خاموشی ناگهانی.', 'it', 'urgent', $unitIds['it'], 1, 'incident', 'IT-CAT-HW'],
                     ['خرابی قطعه سخت‌افزاری', 'IT-HARDWARE', 'مشکوک به خرابی هارد، RAM، پاور یا مادربرد.', 'it', 'urgent', $unitIds['it'], 1, 'incident', 'IT-CAT-HW'],
                     ['نصب یا بازیابی ویندوز', 'IT-PROFILE', 'نصب مجدد یا بازیابی سیستم‌عامل.', 'it', 'normal', $unitIds['it'], 1, 'request', 'IT-CAT-SETUP'],
                     ['نصب درایور تجهیزات', 'IT-DRIVER', 'نصب درایور پرینتر، اسکنر یا سایر تجهیزات.', 'it', 'normal', $unitIds['it'], 1, 'request', 'IT-CAT-SETUP'],
                     ['نصب نرم‌افزار سازمانی', 'IT-SOFTWARE', 'نصب نرم‌افزارهای مورد تأیید سازمان.', 'it', 'normal', $unitIds['it'], 1, 'request', 'IT-CAT-SW'],
                     ['خطای نرم‌افزار نصب‌شده', 'IT-SOFT-ERROR', 'خطا، بسته شدن یا کار نکردن نرم‌افزار.', 'it', 'normal', $unitIds['it'], 1, 'incident', 'IT-CAT-SW'],
                     ['مشکل پرینتر یا اسکنر', 'IT-PRINTER', 'چاپ نکردن، خطای چاپ یا اسکن.', 'it', 'normal', $unitIds['it'], 0, 'incident', 'IT-CAT-PRINT'],
                     ['کیبورد، ماوس و مانیتور', 'IT-PERIPHERAL', 'خرابی یا تعویض تجهیزات جانبی.', 'it', 'normal', $unitIds['it'], 0, 'incident', 'IT-CAT-PERIPH'],
                     ['اختلال شبکه یا اینترنت', 'IT-NET', 'قطعی، کندی یا خطای اتصال شبکه و اینترنت.', 'it', 'urgent', $unitIds['it'], 1, 'incident', 'IT-CAT-NET'],
                     ['اشتراک فایل و پرینتر شبکه', 'IT-SHARE', 'دسترسی به فایل یا پرینتر اشتراکی.', 'it', 'normal', $unitIds['it'], 1, 'request', 'IT-CAT-NET'],
                     ['حساب کاربری و رمز عبور', 'IT-ACCOUNT', 'ساخت حساب، تغییر یا بازیابی رمز.', 'it', 'normal', $unitIds['it'], 0, 'request', 'IT-CAT-ACC'],
                     ['دسترسی به سامانه داخلی', 'IT-ACCESS', 'دسترسی یا اصلاح سطح دسترسی سامانه‌ها.', 'it', 'normal', $unitIds['it'], 0, 'request', 'IT-CAT-ACC'],
                     ['پست الکترونیک سازمانی', 'IT-EMAIL', 'ساخت، بازیابی یا خطای ایمیل سازمانی.', 'it', 'normal', $unitIds['it'], 0, 'request', 'IT-CAT-ACC'],
                     ['بازیابی فایل یا پشتیبان‌گیری', 'IT-BACKUP', 'بازیابی اطلاعات پاک‌شده یا پشتیبان‌گیری.', 'it', 'urgent', $unitIds['it'], 1, 'request', 'IT-CAT-BACKUP'],
                     ['نصب و راه‌اندازی رایانه جدید', 'IT-INSTALL-PC', 'تحویل و راه‌اندازی سیستم جدید.', 'it', 'normal', $unitIds['it'], 1, 'request', 'IT-CAT-SETUP'],
                     ['جابه‌جایی رایانه', 'IT-MOVE', 'انتقال سیستم یا تغییر محل استقرار.', 'it', 'normal', $unitIds['it'], 1, 'request', 'IT-CAT-PERIPH'],
                     ['ثبت یا اصلاح شناسنامه فنی', 'IT-INVENTORY', 'تکمیل شناسنامه و مشخصات فنی سیستم.', 'it', 'normal', $unitIds['it'], 1, 'request', 'IT-CAT-SETUP'],
                     ['سایر خدمات فناوری اطلاعات', 'IT-OTHER', 'موارد دیگر مرتبط با فناوری اطلاعات.', 'it', 'normal', $unitIds['it'], 0, 'request', 'IT-CAT-MISC'],
                     ['تجهیزات و فضای اداری', 'SUP-OFFICE', 'رسیدگی به تجهیزات عمومی یا فضای کار.', 'support', 'normal', $unitIds['support'], 0, 'request', 'SUP-CAT-OFFICE'],
                     ['برق و تأسیسات', 'SUP-ELEC', 'خرابی برق، پریز، روشنایی یا تأسیسات.', 'support', 'normal', $unitIds['support'], 0, 'incident', 'SUP-CAT-UTIL'],
                     ['سرمایش و گرمایش', 'SUP-COOLING', 'خرابی کولر، اسپلیت یا سیستم گرمایش.', 'support', 'normal', $unitIds['support'], 0, 'incident', 'SUP-CAT-UTIL'],
                     ['اثاثیه و مبلمان اداری', 'SUP-FURN', 'تعمیر یا جابه‌جایی میز و صندلی.', 'support', 'normal', $unitIds['support'], 0, 'request', 'SUP-CAT-FURN'],
                     ['نظافت و خدمات', 'SUP-CLEAN', 'درخواست نظافت یا خدمات عمومی.', 'support', 'normal', $unitIds['support'], 0, 'request', 'SUP-CAT-CLEAN'],
                     ['لوازم‌التحریر و ملزومات', 'SUP-STATIONERY', 'درخواست لوازم‌التحریر و ملزومات اداری.', 'support', 'normal', $unitIds['support'], 0, 'request', 'SUP-CAT-STAT'],
                     ['اصلاح یا تأیید رکورد رسانه', 'SUP-CDDVD', 'درخواست اصلاح رکورد CD/DVD.', 'support', 'normal', $unitIds['support'], 0, 'request', 'SUP-CAT-GEN'],
                     ['خدمات عمومی سازمان', 'SUP-GENERAL', 'سایر خدمات عمومی داخلی سازمان.', 'support', 'normal', $unitIds['support'], 0, 'request', 'SUP-CAT-GEN'],
                     ['سایر خدمات پشتیبانی', 'SUP-OTHER', 'موارد دیگر مرتبط با پشتیبانی.', 'support', 'normal', $unitIds['support'], 0, 'request', 'SUP-CAT-MISC'],
                 ] as $service) {
                     $service[] = (int) ($categoryIds[$service[8]] ?? 0) ?: null;
                     $service[8] = $service[9];
                     unset($service[9]);
                     $serviceQuery->execute(array_merge($service, [$adminId]));
                 }
                 $olaLookup = $pdo->prepare('SELECT id FROM ola_policies WHERE name = ? AND department_id IS NULL AND priority = ? LIMIT 1');
                 $olaInsert = $pdo->prepare('INSERT INTO ola_policies (name, department_id, priority, response_minutes, resolution_minutes, escalation_1_minutes, escalation_2_minutes, created_by) VALUES (?, NULL, ?, ?, ?, ?, ?, ?)');
                 $olaUpdate = $pdo->prepare('UPDATE ola_policies SET response_minutes = ?, resolution_minutes = ?, escalation_1_minutes = ?, escalation_2_minutes = ?, created_by = ?, is_active = 1 WHERE id = ?');
                  foreach ([['OLA حیاتی سراسری', 'critical', 30, 240, 240, 480], ['OLA فوری سراسری', 'urgent', 120, 2880, 2880, 4320], ['OLA عادی سراسری', 'normal', 240, 4320, 4320, 5760]] as $ola) {
                     $olaLookup->execute([$ola[0], $ola[1]]);
                     $olaId = $olaLookup->fetchColumn();
                     if ($olaId === false) {
                         $olaInsert->execute([$ola[0], $ola[1], $ola[2], $ola[3], $ola[4], $ola[5], $adminId]);
                     } else {
                         $olaUpdate->execute([$ola[2], $ola[3], $ola[4], $ola[5], $adminId, (int) $olaId]);
                     }
                 }
                $appKey = bin2hex(random_bytes(32));
                $finalConfig = [
                    'app' => ['name' => $_SESSION['install']['app']['name'], 'logo' => $_SESSION['install']['app']['logo'], 'timezone' => 'Asia/Tehran', 'base_url' => '', 'key' => $appKey],
                    'database' => $db,
                    'ldap' => $_SESSION['install']['ldap'],
                    'security' => ['max_upload_mb' => 8, 'allowed_uploads' => ['pdf','png','jpg','jpeg','doc','docx','xls','xlsx','txt','zip'], 'inventory_token' => bin2hex(random_bytes(32))],
                    'food_ticket' => ['odbc_driver' => 'Microsoft Access Driver (*.mdb, *.accdb)', 'web_url' => 'http://127.0.0.1:8080/', 'sso_key' => $appKey],
                ];
                $configCode = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($finalConfig, true) . ";\n";
                 if (file_put_contents($configFile, $configCode, LOCK_EX) === false) {
                     throw new RuntimeException('نوشتن config.php ممکن نیست. دسترسی پوشه را بررسی کنید.');
                 }
                 $configWritten = true;
                 @chmod($configFile, 0640);
                 if (!is_dir(__DIR__ . '/storage/uploads') && !mkdir(__DIR__ . '/storage/uploads', 0750, true) && !is_dir(__DIR__ . '/storage/uploads')) {
                     throw new RuntimeException('پوشه ذخیره پیوست‌ها قابل ایجاد نیست.');
                 }
                 if (file_put_contents($lockFile, gmdate('c'), LOCK_EX) === false) {
                     throw new RuntimeException('ثبت پایان نصب ممکن نیست. دسترسی پوشه را بررسی کنید.');
                 }
                 $lockWritten = true;
                 $pdo->commit();
                 unset($_SESSION['install']);
                  $_SESSION['user_id'] = $adminId;
                 header('Location: index.php?installed=1');
                 exit;
                 } catch (Throwable $exception) {
                     if ($pdo->inTransaction()) {
                         $pdo->rollBack();
                     }
                     if ($configWritten && is_file($configFile)) {
                         @unlink($configFile);
                     }
                     if ($lockWritten && is_file($lockFile)) {
                         @unlink($lockFile);
                     }
                     throw $exception;
                 }
             } catch (Throwable $exception) {
                $error = 'نصب کامل نشد: ' . $exception->getMessage();
            }
        }
    }
}

$data = $_SESSION['install'] ?? [];
$errorHtml = $error !== '' ? '<div class="alert info">' . ih($error) . '</div>' : '';
$csrf = '<input type="hidden" name="csrf" value="' . ih(install_csrf()) . '">';
$stepField = '<input type="hidden" name="step" value="' . $step . '">';
if ($step === 1) {
    $body = $errorHtml . '<p class="muted">اطلاعات ظاهری سامانه و اتصال MySQL را وارد کنید. لوگو اختیاری است.</p><form method="post" id="installStep1" enctype="multipart/form-data">' . $csrf . $stepField . '<div class="form-grid"><label>نام سامانه<input name="app_name" required value="' . ih($data['app']['name'] ?? 'سامانه پشتیبانی سازمان') . '"></label><label>لوگو<input type="file" name="logo" accept="image/png,image/jpeg"><small>PNG یا JPG تا ۲ مگابایت</small></label><label>آدرس MySQL<input name="db_host" required value="' . ih($data['database']['host'] ?? '127.0.0.1') . '"></label><label>پورت<input type="number" name="db_port" value="' . ih($data['database']['port'] ?? 3306) . '"></label><label>نام پایگاه‌داده<input name="db_name" required value="' . ih($data['database']['name'] ?? 'persian_ticketing') . '"></label><label>نام کاربری MySQL<input name="db_user" required value="' . ih($data['database']['user'] ?? 'root') . '"></label><label class="full">رمز MySQL<input type="password" name="db_password" value="' . ih($data['database']['password'] ?? '') . '"></label><label class="full switch-row"><input type="checkbox" name="db_drop_existing" value="1" ' . (!empty($data['database']['drop_existing']) ? 'checked' : '') . '> حذف پایگاه‌داده موجود با همین نام و ساخت از نو</label><small class="full" style="color:#b42318">هشدار: با فعال‌بودن این گزینه، اگر پایگاه‌داده‌ای با این نام از قبل وجود داشته باشد کاملاً حذف و تمام داده‌های آن پاک می‌شود.</small></div><div class="actions"><button class="button secondary" name="action" value="test_db">تست اتصال MySQL</button><button class="button" name="action" value="next">ادامه</button></div></form><script>(function(){var f=document.getElementById("installStep1");if(!f){return;}var c=f.querySelector("input[name=db_drop_existing]");var conf=f.querySelector("input[name=db_drop_confirm]");var confLabel=conf?conf.closest("label"):null;function sync(){if(!c||!confLabel){return;}confLabel.style.display=c.checked?"block":"none";if(!c.checked&&conf){conf.value="";}}if(c){c.addEventListener("change",sync);sync();}f.addEventListener("submit",function(e){if(c&&c.checked&&e.submitter&&e.submitter.value!=="test_db"){if(!conf||!conf.value||conf.value!== (f.querySelector("input[name=db_name]")||{}).value){alert("برای حذف پایگاه، نام پایگاه را دقیقاً در کادر تأیید وارد کنید.");e.preventDefault();return;}if(!confirm("هشدار: پایگاه‌داده موجود با همین نام حذف و از نو ساخته می‌شود. ادامه می‌دهید؟")){e.preventDefault();}}});})();</script>';
    $body = str_replace(
        '<div class="actions">',
        '<label class="full">برای تأیید حذف پایگاه‌داده، نام آن را دوباره وارد کنید<input name="db_drop_confirm" autocomplete="off"></label><div class="actions">',
        $body
    );
    install_layout('اطلاعات اولیه سامانه', $body, 1);
}
if ($step === 2) {
    $ldap = $data['ldap'] ?? [];
    $body = $errorHtml . '<p class="muted">برای ورود کاربران شبکه، اطلاعات LDAP/Active Directory را وارد کنید. اگر فعلاً آماده نیست، گزینه فعال‌سازی را خاموش بگذارید؛ بعداً از تنظیمات قابل تغییر است.</p><form method="post">' . $csrf . $stepField . '<label class="switch-row"><input type="checkbox" name="ldap_enabled" value="1" ' . (!empty($ldap['enabled']) ? 'checked' : '') . '> اتصال ورود کاربران به Active Directory فعال باشد</label><div class="form-grid"><label>آدرس Domain Controller<input name="ldap_host" placeholder="dc01.example.local" value="' . ih($ldap['host'] ?? '') . '"></label><label>پورت<input type="number" name="ldap_port" value="' . ih($ldap['port'] ?? 389) . '"></label><label>Base DN<input name="ldap_base_dn" placeholder="DC=example,DC=local" value="' . ih($ldap['base_dn'] ?? '') . '"></label><label>Domain suffix اختیاری<input name="domain_suffix" placeholder="example.local" value="' . ih($ldap['domain_suffix'] ?? '') . '"></label><label class="full">حساب سرویس برای جست‌وجو<input name="ldap_bind_dn" placeholder="CN=svc-ticket,OU=Service Accounts,DC=example,DC=local" value="' . ih($ldap['bind_dn'] ?? '') . '"></label><label class="full">رمز حساب سرویس<input type="password" name="ldap_bind_password" value="' . ih($ldap['bind_password'] ?? '') . '"></label></div><label class="switch-row"><input type="checkbox" name="ldap_ssl" value="1" ' . (!empty($ldap['ssl']) ? 'checked' : '') . '> استفاده از LDAPS (پورت معمول ۶۳۶)</label><div class="actions"><button class="button secondary" name="action" value="test_ldap">تست اتصال دامین</button><button class="button" name="action" value="next">ادامه</button></div></form>';
    install_layout('اتصال به دامین کنترلر', $body, 2);
}
$body = $errorHtml . '<p class="muted">این حساب برای مدیریت سامانه استفاده می‌شود و مستقل از Active Directory است.</p><form method="post">' . $csrf . $stepField . '<div class="form-grid"><label>نام کاربری مدیر<input name="admin_username" required value="admin" autocomplete="username"></label><label>نام و نام خانوادگی<input name="admin_full_name" required value="مدیر سامانه"></label><label class="full">رمز عبور مدیر<input type="password" name="admin_password" required minlength="8" autocomplete="new-password"><small>حداقل ۸ کاراکتر</small></label></div><div class="actions"><button class="button" name="action" value="finish">ساخت سامانه و ورود</button></div></form>';
install_layout('ساخت مدیر اولیه', $body, 3);

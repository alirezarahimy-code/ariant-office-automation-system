<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/food-ticket-env.php')) {
    require_once __DIR__ . '/food-ticket-env.php';
}
if (!function_exists('food_ticket_enqueue_print') && is_file(__DIR__ . '/food-ticket-runtime.php')) {
    require_once __DIR__ . '/food-ticket-runtime.php';
}
if (is_file(__DIR__ . '/food-ticket-engine.php')) {
    require_once __DIR__ . '/food-ticket-engine.php';
}
if (is_file(__DIR__ . '/food-ticket-netprint.php')) {
    require_once __DIR__ . '/food-ticket-netprint.php';
}
if (is_file(__DIR__ . '/food-ticket-groups.php')) {
    require_once __DIR__ . '/food-ticket-groups.php';
}
if (is_file(__DIR__ . '/food-order.php')) {
    require_once __DIR__ . '/food-order.php';
}
if (is_file(__DIR__ . '/food-ticket-order-source.php')) {
    require_once __DIR__ . '/food-ticket-order-source.php';
}
if (is_file(__DIR__ . '/food-ticket-web-host.php')) {
    require_once __DIR__ . '/food-ticket-web-host.php';
}
if (is_file(__DIR__ . '/food-ticket-templates.php')) {
    require_once __DIR__ . '/food-ticket-templates.php';
}
if (is_file(__DIR__ . '/food-order-import.php')) {
    require_once __DIR__ . '/food-order-import.php';
}
if (is_file(__DIR__ . '/food-ticket-import.php')) {
    require_once __DIR__ . '/food-ticket-import.php';
}

/** نسخهٔ پنل (food-ticket-web/index.html). پنل همان عدد را دارد و اگر مرورگر
 *  نسخهٔ کش‌شدهٔ قدیمی را نشان دهد، خودش هشدار می‌دهد. */
const FOOD_TICKET_PANEL_VERSION = '1.37.10';

function food_ticket_panel_version(): string
{
    return FOOD_TICKET_PANEL_VERSION;
}

function food_ticket_is_primary_admin(array $user): bool
{
    return function_exists('user_is_primary_admin') ? user_is_primary_admin($user) : (($user['role'] ?? '') === 'admin' && (int) ($user['is_primary_admin'] ?? 0) === 1);
}

function food_ticket_is_support_manager(array $user): bool
{
    return in_array((string) ($user['role'] ?? ''), ['manager', 'support_manager'], true) && user_service_group($user) === 'support';
}

function food_ticket_is_allowed(array $user): bool
{
    if (function_exists('user_can')) {
        return user_can_any($user, ['food.dashboard', 'food.monitor', 'food.reports', 'food.employees', 'food.guest', 'food.groups', 'food.db', 'food.printer', 'food.design', 'food.health', 'food.menu', 'food.order_close']);
    }
    return food_ticket_is_primary_admin($user) || food_ticket_is_support_manager($user);
}

/** کد ویرایش لازم برای مسیرهای نوشتن هر بخش (نگاشت مسیر ← کد ویرایش). */
function food_ticket_route_edit_permission(string $route): ?string
{
    if (in_array($route, ['food-groups', 'absence-save', 'absence-clear'], true)) {
        return 'food.groups_edit';
    }
    if (in_array($route, ['process', 'delivery', 'cleanup-source_row', 'reprint-errors'], true)) {
        return 'food.monitor_edit';
    }
    if ($route === 'orders-import') {
        return 'food.db_edit';
    }
    if (in_array($route, ['employees', 'employees-import'], true)) {
        return 'food.employees_edit';
    }
    if ($route === 'guest-cards') {
        return 'food.guest_edit';
    }
    if (in_array($route, ['config', 'database-file', 'test-connections', 'diag-source_row-delete', 'browse-file', 'browse-folder', 'browse-list'], true)
        || (function_exists('food_ticket_legacy_key') && $route === food_ticket_legacy_key('route_test'))
        || (function_exists('food_ticket_route_is_test_attendance') && food_ticket_route_is_test_attendance($route))) {
        return 'food.db_edit';
    }
    if (in_array($route, ['printers', 'test-print', 'calibrate-print', 'print-align', 'print-diag'], true)) {
        return 'food.printer_edit';
    }
    if ($route === 'template' || $route === 'templates' || str_starts_with($route, 'templates/')) {
        return 'food.design_edit';
    }
    if ($route === 'self-test') {
        return 'food.health_edit';
    }
    return null;
}

/** این مسیرها حتی با GET کار ثبتی/مخرب انجام می‌دهند، پس همیشه کد ویرایش می‌خواهند. */
function food_ticket_route_edit_always(string $route): bool
{
    return $route === 'diag-source_row-delete';
}

function food_ticket_route_allowed(array $user, string $route): bool
{
    if (str_starts_with($route, 'food-menu/')) {
        if (food_ticket_is_primary_admin($user)) {
            return true;
        }
        $menuPermission = match ($route) {
            'food-menu/status', 'food-menu/month' => 'food.menu|food.order_close',
            'food-menu/catalog', 'food-menu/day' => 'food.menu',
            'food-menu/orders', 'food-menu/orders-export', 'food-menu/statistics', 'food-menu/export' => 'food.order_close',
            default => null,
        };
        if ($menuPermission === null || !function_exists('user_can')) {
            return false;
        }
        // ذخیرهٔ کاتالوگ و روز (نوشتن) فقط با ویرایش برنامه غذایی.
        if (in_array($route, ['food-menu/catalog', 'food-menu/day'], true) && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return user_can($user, 'food.menu_edit');
        }
        if ($menuPermission === 'food.menu|food.order_close') {
            return user_can($user, 'food.menu') || user_can($user, 'food.order_close');
        }
        return user_can($user, $menuPermission);
    }
    if (food_ticket_is_primary_admin($user)) {
        return true;
    }
    if (!function_exists('user_can')) {
        return food_ticket_is_support_manager($user)
            && (in_array($route, ['login', 'sso-login', 'monitoring', 'employees', 'orders', 'reports/export', 'dashboard', 'health', 'self-test', 'test-connections', 'config', 'database-file', 'session'], true)
                || (function_exists('food_ticket_route_is_test_attendance') && food_ticket_route_is_test_attendance($route)));
    }
    if (in_array($route, ['login', 'sso-login'], true)) {
        return food_ticket_is_allowed($user);
    }
    $routePermission = [
        'dashboard' => 'food.dashboard',
        'monitoring' => 'food.monitor',
        'absent' => 'food.reports',
        'guest-requests-report' => 'food.reports',
        'orders' => 'food.dashboard',
        'reports/export' => 'food.reports',
        'employees' => 'food.employees',
        'employees-import' => 'food.employees',
        'orders-import' => 'food.db',
        'employees-sample' => 'food.employees',
        'guest-cards' => 'food.guest',
        'guest-cap' => 'food.guest',
        'food-groups' => 'food.groups',
        'config' => 'food.db',
        'database-file' => 'food.db',
        'printers' => 'food.printer',
        'test-print' => 'food.printer',
        'calibrate-print' => 'food.printer',
        'print-align' => 'food.printer',
        'print-diag' => 'food.printer',
        'test-connections' => 'food.db',
        'test-attendance' => 'food.db',
        'template' => 'food.design',
        'templates' => 'food.design',
        'templates/preview' => 'food.design',
        'templates/delete' => 'food.design',
        'templates/activate' => 'food.design',
        'templates/logo' => 'food.design',
        'health' => 'food.health',
        'self-test' => 'food.health',
        'process' => 'food.monitor',
        'reprint-errors' => 'food.monitor',
        // غیبت روزانه و تحویل غذا بخشی از فرم گروه‌ها هستند؛ ویرایش غیبت با food.groups،
        // اما «اصلاح بعد از قفل» در خودِ توابع با food.groups_override کنترل می‌شود.
        'absence-status' => 'food.groups',
        'absence-save' => 'food.groups',
        'absence-clear' => 'food.groups',
        'delivery' => 'food.monitor',
        'diag-source_row-delete' => 'food.db',
        // سازگاری: لینک قدیمی «آزمون اتصال منبع تردد»
        (function_exists('food_ticket_legacy_key') ? food_ticket_legacy_key('route_test') : 'test-source') => 'food.db',
        'cleanup-source_row' => 'food.monitor',
    ][$route] ?? null;
    // مشاهده با کد بخش؛ هر کار ثبتی/مخرب با کد «ویرایش» همان بخش.
    $routeEdit = food_ticket_route_edit_permission($route);
    // فقط مسیرهایی که در نگاشت دسترسی‌اند تغییر می‌کنند؛ مسیر ناشناخته همچنان بسته می‌ماند.
    if ($routeEdit !== null && $routePermission !== null) {
        $routeIsRead = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET';
        if (!$routeIsRead || food_ticket_route_edit_always($route)) {
            $routePermission = $routeEdit;
        }
    }
    return $routePermission !== null && user_can($user, $routePermission);
}

function require_food_ticket_access(): array
{
    $user = require_login();
    if (!food_ticket_is_allowed($user)) {
        http_response_code(403);
        exit('این بخش فقط برای ادمین اصلی و مدیر پشتیبانی قابل استفاده است.');
    }
    return $user;
}

function food_ticket_web_url(?array $user = null): string
{
    $base = rtrim(food_ticket_windows_service_base_url(), '/') . '/index.html?mode=integrated';
    $brand = food_ticket_brand_params();
    if ($brand) {
        $base .= '&' . http_build_query($brand);
    }
    if (!$user || !food_ticket_is_allowed($user)) {
        return $base;
    }
    return $base . '&sso=' . rawurlencode(food_ticket_sso_token($user));
}

function food_ticket_windows_service_base_url(): string
{
    $base = trim((string) cfg('food_ticket.web_url', 'http://127.0.0.1:8080/'));
    if (!preg_match('#^https?://[^\s/]+(?::\d+)?(?:/[^\s]*)?$#i', $base)) {
        $base = 'http://127.0.0.1:8080/';
    }
    $base = preg_replace('#/index\.html(?:\?.*)?$#i', '', $base) ?: $base;
    return rtrim($base, '/') . '/';
}

function food_ticket_sso_token(array $user): string
{
    $jti = bin2hex(random_bytes(16));
    $payload = rtrim(strtr(base64_encode((string) json_encode([
        'user_id' => (int) ($user['id'] ?? 0),
        'role' => (string) ($user['role'] ?? ''),
        'service_group' => user_service_group($user),
        'exp' => time() + 120,
        'jti' => $jti,
    ], JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    $key = (string) cfg('food_ticket.sso_key', cfg('app.key', ''));
    $signature = hash_hmac('sha256', $payload, $key);
    return $payload . '.' . $signature;
}

function food_ticket_sso_consume_jti(string $jti): bool
{
    $jti = preg_replace('/[^a-f0-9]/i', '', $jti) ?? '';
    if (strlen($jti) < 16) {
        return false;
    }
    $dir = APP_ROOT . '/storage/sso_jti';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $path = $dir . '/' . $jti . '.used';
    if (is_file($path)) {
        return false;
    }
    @file_put_contents($path, (string) time());
    // پاکسازی توکن‌های قدیمی‌تر از ۱۰ دقیقه
    foreach (glob($dir . '/*.used') ?: [] as $file) {
        if (@filemtime($file) < time() - 600) {
            @unlink($file);
        }
    }
    return true;
}

function food_ticket_main_url(): string
{
    $configured = trim((string) cfg('app.base_url', ''));
    if ($configured !== '' && preg_match('#^https?://#i', $configured)) {
        return rtrim($configured, '/') . '/index.php';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1'));
    $directory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    $directory = rtrim($directory, '/.');
    return $scheme . '://' . $host . ($directory === '' ? '' : $directory) . '/index.php';
}

function food_ticket_brand_params(): array
{
    $name = trim((string) setting('food_ticket_brand_name', (string) setting('app_name', (string) cfg('app.name', 'سامانه چاپ فیش غذا'))));
    if (function_exists('food_ticket_is_legacy_brand_name') ? food_ticket_is_legacy_brand_name($name) : $name === '') {
        $name = 'سامانه چاپ فیش غذا';
    }
    $logo = trim((string) setting('food_ticket_brand_logo', (string) setting('app_logo', (string) cfg('app.logo', ''))));
    if ($logo !== '' && !preg_match('#^https?://#i', $logo)) {
        $mainUrl = food_ticket_main_url();
        $mainRoot = substr($mainUrl, 0, -strlen('/index.php'));
        $logo = rtrim($mainRoot, '/') . '/' . ltrim($logo, '/');
    }
    return array_filter([
        'brand_name' => $name,
        'brand_logo' => $logo,
        'return_url' => food_ticket_main_url(),
    ], static fn (string $value): bool => $value !== '');
}

/**
 * یافتن مسیر واقعی فایل روی ویندوز (حروف بزرگ/کوچک مهم نیست)
 * اگر فایل پیدا نشود، اولین نامزد برگردانده می‌شود.
 */
function food_ticket_resolve_path(array $candidates): string
{
    foreach ($candidates as $p) {
        $p = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $p);
        if ($p !== '' && is_file($p)) {
            $rp = realpath($p);
            return $rp !== false ? $rp : $p;
        }
    }
    $first = (string) ($candidates[0] ?? '');
    return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $first);
}

/**
 * مسیر پیش‌فرض فایل منبع تردد (Access).
 *
 * مقادیر از لایهٔ محیطی خوانده می‌شوند (نصب‌های موجود ابزار حضور و غیاب) و از پنل
 * («تنظیمات سیستم → اتصال پایگاه‌ها») قابل تغییرند. هیچ مسیر ثابتی داخل کد نوشته نشده.
 */
function food_ticket_attendance_default_path(): string
{
    $paths = food_ticket_env_legacy_paths();
    return food_ticket_resolve_path(array_slice($paths, 0, 4));
}

function food_ticket_attendance_factory_password(): string
{
    return food_ticket_env_value('factory_password');
}

function food_ticket_config(bool $reload = false): array
{
    static $config;
    if ($reload) {
        $config = null;
    }
    if ($config !== null) {
        return $config;
    }
    $defaults = [
        'id' => 1, 'enabled' => 1, 'attendance_path' => food_ticket_attendance_default_path(), 'attendance_password_enc' => '',
        'printer_mode' => 'tcp_raw',
        'printer_host' => '', 'printer_port' => 9100, 'printer_share' => '',
        'poll_seconds' => 2, 'max_batch' => 100, 'guest_card_uids' => '',
        'max_guest_daily' => 20, 'cut_source_rows' => 1, 'updated_by' => null,
    ];
    try {
        $colPath = food_ticket_db_column('attendance_path');
        $colPwd = food_ticket_db_column('attendance_password_enc');
        $colCut = food_ticket_db_column('cut_source_rows');
        foreach ([$colPath, $colPwd, $colCut] as $column) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                throw new RuntimeException('نام ستون تنظیمات تردد معتبر نیست.');
            }
        }
        $sql = 'SELECT id, enabled, `' . $colPath . '` AS attendance_path, `' . $colPwd . '` AS attendance_password_enc, '
            . 'printer_mode, printer_host, printer_port, printer_share, poll_seconds, max_batch, guest_card_uids, '
            . 'max_guest_daily, `' . $colCut . '` AS cut_source_rows, updated_by FROM food_ticket_config WHERE id = 1';
        $row = db()->query($sql)->fetch() ?: [];
        // پایگاه‌داده‌های نصب‌شده نام ستون‌های قدیمی دارند → نگاشت به نام‌های منطقی کد
        if (function_exists('food_ticket_config_alias_row')) {
            $row = food_ticket_config_alias_row($row);
        }
        $config = array_merge($defaults, $row);
        $config['printer_mode'] = food_ticket_normalize_printer_mode($config['printer_mode'] ?? 'tcp_raw');
    } catch (Throwable) {
        $config = $defaults;
    }
    // مسیر ذخیره‌شده در پنل بر پیش‌فرض‌های قدیمی اولویت دارد؛ فقط نصب‌هایی که هنوز
    // مسیر خالی دارند از مسیر محیطیِ کشف‌شده استفاده می‌کنند.
    if (trim((string) ($config['attendance_path'] ?? '')) === '') {
        $config['attendance_path'] = $defaults['attendance_path'];
    }
    $config['enabled'] = 1;
    $config['cut_source_rows'] = 1;
    return $config;
}


function food_ticket_normalize_printer_mode(mixed $mode): string
{
    $mode = strtolower(trim((string) $mode));
    // مقدار ذخیره‌شده باید با ENUM جدول food_ticket_config هم‌خوان باشد:
    // ENUM('tcp_raw','windows_share') — windows_queue عمداً به windows_share نگاشت می‌شود.
    if (in_array($mode, ['windows', 'windows_queue', 'windows_share', 'share', 'windows-spooler', 'spooler', 'win'], true)) {
        return 'windows_share';
    }
    if (in_array($mode, ['socket', 'tcp', 'tcp_raw', 'raw', 'network'], true)) {
        return 'tcp_raw';
    }
    return $mode !== '' ? $mode : 'tcp_raw';
}

/**
 * آدرس چاپگر شبکه را تمیز می‌کند: tcp://، فاصله و «:پورت» چسبیده به آدرس را جدا می‌کند و اعتبارسنجی می‌کند.
 * @return array{0:string,1:int} [host, port]
 */
function food_ticket_normalize_printer_host(string $host, int $port = 9100): array
{
    $host = trim($host);
    $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host);
    $host = trim($host, " \t\n\r\0\x0B/\\");
    if (preg_match('/^(\[[0-9a-f:]+\]|[^:\s]+):(\d{1,5})$/i', $host, $m)) {
        $host = trim($m[1], '[]');
        $port = (int) $m[2];
    }
    if ($host !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $host) && !filter_var($host, FILTER_VALIDATE_IP)) {
        throw new RuntimeException('آدرس چاپگر شبکه معتبر نیست. فقط IP یا نام میزبان (مثلاً 192.168.1.50) وارد کنید.');
    }
    return [$host, max(1, min(65535, $port))];
}

/** برچسب خوانا و مقصد واقعی چاپ بر اساس حالت ذخیره‌شده (برای لاگ و پیام‌ها). */
function food_ticket_printer_label(array $config): string
{
    $mode = food_ticket_normalize_printer_mode($config['printer_mode'] ?? 'tcp_raw');
    $host = trim((string) ($config['printer_host'] ?? ''));
    $share = trim((string) ($config['printer_share'] ?? ''));
    if ($mode === 'tcp_raw') {
        return $host !== '' ? $host . ':' . max(1, (int) ($config['printer_port'] ?? 9100)) : '';
    }
    return $share !== '' ? $share : $host;
}

/**
 * ستون printer_mode باید VARCHAR یا ENUM شامل هر دو مقدار باشد؛ در نصب‌های قدیمی ENUM ناقص باعث می‌شد
 * مقدار tcp_raw ذخیره نشود و حالت به ویندوز برگردد. این تابع یک‌بار ساختار را اصلاح می‌کند.
 */
function food_ticket_ensure_printer_mode_column(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $row = db()->query("SHOW COLUMNS FROM food_ticket_config LIKE 'printer_mode'")->fetch() ?: [];
        $type = strtolower((string) ($row['Type'] ?? ''));
        $isEnum = str_starts_with($type, 'enum');
        if ($isEnum && (!str_contains($type, "'tcp_raw'") || !str_contains($type, "'windows_share'"))) {
            db()->exec("ALTER TABLE food_ticket_config MODIFY printer_mode VARCHAR(20) NOT NULL DEFAULT 'tcp_raw'");
        }
    } catch (Throwable $e) {
        error_log('[food-ticket] ensure printer_mode column: ' . $e->getMessage());
    }
}

function food_ticket_installed(): bool
{
    try {
        return (bool) db()->query("SHOW TABLES LIKE 'food_ticket_config'")->fetchColumn()
            && (bool) db()->query("SHOW TABLES LIKE 'food_ticket_events'")->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function food_ticket_key(): string
{
    return hash('sha256', (string) cfg('app.key', 'food-ticket-change-me'), true);
}

function food_ticket_encrypt(string $value): string
{
    if ($value === '') {
        return '';
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($value, 'aes-256-gcm', food_ticket_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $cipher === false ? '' : base64_encode($iv . $tag . $cipher);
}

function food_ticket_decrypt(?string $value): string
{
    if (!$value) {
        return '';
    }
    $raw = base64_decode($value, true);
    if ($raw === false || strlen($raw) < 28) {
        return '';
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', food_ticket_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

/**
 * رمز واقعیِ فایل Access منبع تردد را برمی‌گرداند.
 *
 * ترتیب اولویت (پچ ۱.۳۴ — رفع P0):
 *   ۱) رمز ذخیره‌شده در جدول تنظیمات با کلید attendance_password_enc (فرم «تنظیمات چاپ» یا ابزار
 *      tools/set_attendance_password.php). این مقدار AES-256-GCM رمزنگاری‌شده است.
 *   ۲) رمز داخل food-ticket-config.php (کلید password یا attendance_password) — استقرارهای قدیمی.
 *   ۳) رمز ثابت پیش‌فرض کارخانه (مقدار محیطی، قابل تغییر از پنل) — سازگاری کامل با نصب‌های فعلی.
 *
 * علت: قبلاً همیشه رمز ثابت کارخانه برگردانده می‌شد و اگر رمز فایل Access عوض می‌شد، هیچ راهی
 * برای به‌روزرسانی از پنل نبود و موتور غذا با خطای اتصال می‌خوابید.
 */
function food_ticket_attendance_password(array $config): string
{
    // ۱) رمز ذخیره‌شده در جدول food_ticket_config (همان جایی که فرم/ابزار ذخیره می‌کند)
    try {
        $row = function_exists('food_ticket_config') ? food_ticket_config() : [];
        $candidates = [
            (string) ($row['attendance_password_enc'] ?? ''),
            function_exists('food_ticket_setting_password_enc') ? food_ticket_setting_password_enc() : '',
        ];
        foreach ($candidates as $stored) {
            if ($stored === '') {
                continue;
            }
            $plain = food_ticket_decrypt($stored);
            if ($plain !== '') {
                return $plain;
            }
        }
        if (trim((string) ($candidates[0] ?? '')) !== '') {
            // ciphertext هست ولی باز نشد (app.key عوض شده) → به مسیرهای بعدی برمی‌گردیم
            error_log('[food-ticket] attendance_password_enc decrypt failed (app.key mismatch?)');
        }
    } catch (Throwable) {
        // دسترسی به جدول تنظیمات ممکن نبود → مسیرهای بعدی
    }

    // ۲) مقدار داخل فایل تنظیمات food-ticket-config.php
    foreach (['password', 'attendance_password', 'access_password'] as $key) {
        $value = trim((string) ($config[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    // ۳) رمز ثابت پیش‌فرض
    return food_ticket_attendance_factory_password();
}

/** آیا رمز منبع تردد از تنظیمات (نه مقدار پیش‌فرض) آمده است؟ برای نمایش وضعیت در ابزار/پنل. */
function food_ticket_attendance_password_source(): string
{
    try {
        $row = function_exists('food_ticket_config') ? food_ticket_config() : [];
        $stored = trim((string) ($row['attendance_password_enc'] ?? ''));
        if ($stored !== '') {
            return food_ticket_decrypt($stored) !== '' ? 'settings' : 'settings_undecryptable';
        }
        $fallback = function_exists('food_ticket_setting_password_enc') ? food_ticket_setting_password_enc() : '';
        if ($fallback !== '') {
            return food_ticket_decrypt($fallback) !== '' ? 'settings' : 'settings_undecryptable';
        }
    } catch (Throwable) {
    }
    return 'default';
}

function food_ticket_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_ ]+$/u', $name)) {
        throw new RuntimeException('نام جدول Access معتبر نیست.');
    }
    return '[' . str_replace(']', ']]', $name) . ']';
}

function food_ticket_odbc(string $path, string $password = '')
{
    $path = trim(str_replace(['/', "\0"], [DIRECTORY_SEPARATOR === '\\' ? '\\' : '/', ''], $path));
    // نرمال‌سازی مسیر ویندوز
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $path = str_replace('/', '\\', $path);
    }
    if ($path === '') {
        throw new RuntimeException('مسیر دیتابیس Access خالی است.');
    }
    if (!is_file($path)) {
        throw new RuntimeException('فایل Access در مسیر داده‌شده پیدا نشد (از نظر PHP): ' . $path . ' — مسیر را از همان سرور Windows که Apache/PHP روی آن اجرا می‌شود بررسی کنید.');
    }
    // 1) PHP ODBC — چند نام رایج درایور
    $odbcErrors = [];
    if (function_exists('odbc_connect')) {
        $drivers = array_unique(array_filter([
            (string) cfg('food_ticket.odbc_driver', ''),
            'Microsoft Access Driver (*.mdb, *.accdb)',
            'Microsoft Access Driver (*.mdb)',
            'Driver do Microsoft Access (*.mdb)',
        ]));
        foreach ($drivers as $driver) {
            $connection = sprintf('Driver={%s};Dbq=%s;Uid=Admin;Pwd=%s;', $driver, $path, $password);
            $handle = @odbc_connect($connection, '', '');
            if ($handle) {
                return $handle;
            }
            $odbcErrors[] = $driver . ': ' . (string) odbc_errormsg();
        }
    } else {
        $odbcErrors[] = 'افزونه odbc در PHP نصب/فعال نیست';
    }
    $odbcError = implode(' | ', $odbcErrors);
    // 2) Fallback: PowerShell + ACE/Jet/ADODB/ODBC (بدون نیاز به php_odbc)
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        return [
            '__ft_ps' => true,
            'path' => $path,
            'password' => $password,
            'odbc_hint' => $odbcError,
        ];
    }
    throw new RuntimeException('خواندن Access ممکن نشد. ODBC: ' . $odbcError . ' — روی ویندوز از PowerShell/ACE هم پشتیبانی می‌شود.');
}

function food_ticket_is_ps_access($connection): bool
{
    return is_array($connection) && !empty($connection['__ft_ps']);
}

function food_ticket_access_close($connection): void
{
    if (food_ticket_is_ps_access($connection)) {
        return;
    }
    if (is_resource($connection) || (is_object($connection) && function_exists('odbc_close'))) {
        @odbc_close($connection);
    }
}

/**
 * خواندن جدول Access با PowerShell + Microsoft ACE/Jet OLEDB
 */
function food_ticket_access_rows_powershell(array $handle, string $table, int $limit = 500, ?string $orderSql = null, ?string $whereSql = null): array
{
    $path = (string) ($handle['path'] ?? '');
    $password = (string) ($handle['password'] ?? '');
    $limit = max(1, min(50000, $limit));
    $sql = 'SELECT TOP ' . $limit . ' * FROM ' . food_ticket_ident($table);
    if ($whereSql) {
        $sql .= ' WHERE ' . $whereSql;
    }
    if ($orderSql) {
        $sql .= ' ORDER BY ' . $orderSql;
    }
    $payload = base64_encode((string) json_encode([
        'path' => $path,
        'password' => $password,
        'sql' => $sql,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    // چند روش اتصال: OLEDB دستی، ADODB COM، ODBC — برای دور زدن خطای ISAM
    $ps = <<<'PS'
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding $false
$OutputEncoding = New-Object System.Text.UTF8Encoding $false
$data = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__FT_PAYLOAD__')) | ConvertFrom-Json
$path = [string]$data.path
$pwd = [string]$data.password
$sql = [string]$data.sql
if (-not (Test-Path -LiteralPath $path)) { [Console]::Error.WriteLine("FILE_NOT_FOUND: $path"); exit 3 }

function Convert-Rows($readerOrTable) {
  $rows = @()
  if ($readerOrTable -is [System.Data.DataTable]) {
    foreach ($row in $readerOrTable.Rows) {
      $item = @{}
      foreach ($column in $readerOrTable.Columns) {
        $value = $row[$column.ColumnName]
        if ($value -is [System.DBNull]) { $item[$column.ColumnName] = $null }
        elseif ($value -is [datetime]) { $item[$column.ColumnName] = $value.ToString('yyyy-MM-dd HH:mm:ss') }
        else { $item[$column.ColumnName] = $value }
      }
      $rows += $item
    }
  }
  return $rows
}

$errors = @()

# --- A) OLEDB با رشته اتصال دستی (بدون ConnectionStringBuilder) ---
$oleProviders = @('Microsoft.ACE.OLEDB.12.0','Microsoft.ACE.OLEDB.16.0','Microsoft.Jet.OLEDB.4.0')
foreach ($prov in $oleProviders) {
  $conn = $null
  try {
    $cs = "Provider=$prov;Data Source=$path;Persist Security Info=False;"
    if ($pwd -ne '') { $cs += "Jet OLEDB:Database Password=$pwd;" }
    $conn = New-Object System.Data.OleDb.OleDbConnection($cs)
    $conn.Open()
    $cmd = $conn.CreateCommand(); $cmd.CommandText = $sql
    $adapter = New-Object System.Data.OleDb.OleDbDataAdapter($cmd)
    $table = New-Object System.Data.DataTable
    [void]$adapter.Fill($table)
    $rows = Convert-Rows $table
    $json = if ($rows.Count -eq 0) { '[]' } else { (ConvertTo-Json -InputObject $rows -Compress -Depth 6) }
  $bytes = [Text.Encoding]::UTF8.GetBytes([string]$json)
  Write-Output ('B64:' + [Convert]::ToBase64String($bytes))
    exit 0
  } catch {
    $errors += "OLEDB $prov : $($_.Exception.Message)"
  } finally {
    if ($conn) { try { $conn.Dispose() } catch {} }
  }
}

# --- B) ADODB COM ---
foreach ($prov in $oleProviders) {
  $conn = $null
  try {
    $cs = "Provider=$prov;Data Source=$path;"
    if ($pwd -ne '') { $cs += "Jet OLEDB:Database Password=$pwd;" }
    $conn = New-Object -ComObject ADODB.Connection
    $conn.Open($cs)
    $rs = $conn.Execute($sql)
    $rows = @()
    while (-not $rs.EOF) {
      $item = @{}
      foreach ($field in $rs.Fields) {
        $v = $field.Value
        if ($null -eq $v) { $item[$field.Name] = $null }
        elseif ($v -is [datetime]) { $item[$field.Name] = ([datetime]$v).ToString('yyyy-MM-dd HH:mm:ss') }
        else { $item[$field.Name] = "$v" }
      }
      $rows += $item
      $rs.MoveNext()
    }
    $rs.Close(); $conn.Close()
    $json = if ($rows.Count -eq 0) { '[]' } else { (ConvertTo-Json -InputObject $rows -Compress -Depth 6) }
  $bytes = [Text.Encoding]::UTF8.GetBytes([string]$json)
  Write-Output ('B64:' + [Convert]::ToBase64String($bytes))
    exit 0
  } catch {
    $errors += "ADODB $prov : $($_.Exception.Message)"
    if ($conn) { try { $conn.Close() } catch {} }
  }
}

# --- C) ODBC Access Driver ---
$odbcDrivers = @(
  'Microsoft Access Driver (*.mdb, *.accdb)',
  'Microsoft Access Driver (*.mdb)'
)
foreach ($drv in $odbcDrivers) {
  $conn = $null
  try {
    $cs = "Driver={$drv};Dbq=$path;Uid=Admin;Pwd=$pwd;"
    $conn = New-Object System.Data.Odbc.OdbcConnection($cs)
    $conn.Open()
    $cmd = $conn.CreateCommand(); $cmd.CommandText = $sql
    $adapter = New-Object System.Data.Odbc.OdbcDataAdapter($cmd)
    $table = New-Object System.Data.DataTable
    [void]$adapter.Fill($table)
    $rows = Convert-Rows $table
    $json = if ($rows.Count -eq 0) { '[]' } else { (ConvertTo-Json -InputObject $rows -Compress -Depth 6) }
  $bytes = [Text.Encoding]::UTF8.GetBytes([string]$json)
  Write-Output ('B64:' + [Convert]::ToBase64String($bytes))
    exit 0
  } catch {
    $errors += "ODBC $drv : $($_.Exception.Message)"
  } finally {
    if ($conn) { try { $conn.Dispose() } catch {} }
  }
}

[Console]::Error.WriteLine(($errors -join ' | '))
exit 2
PS;
    $ps = str_replace('__FT_PAYLOAD__', $payload, $ps);
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ft_acc_' . bin2hex(random_bytes(4)) . '.ps1';
    if (@file_put_contents($tmp, "\xEF\xBB\xBF" . $ps) === false) {
        throw new RuntimeException('نوشتن اسکریپت خواندن Access ناموفق بود.');
    }
    // ترجیح powershell 64-bit سیستم
    $psExe = 'powershell';
    $sysPs = getenv('SystemRoot') ? (rtrim((string) getenv('SystemRoot'), '\\') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe') : '';
    if ($sysPs !== '' && is_file($sysPs)) {
        $psExe = $sysPs;
    }
    $cmd = escapeshellarg($psExe) . ' -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($tmp);
    $output = [];
    $code = 0;
    @exec($cmd . ' 2>&1', $output, $code);
    @unlink($tmp);
    $text = trim(implode("\n", $output));
    if ($code === 0) {
        // خروجی PowerShell به صورت B64: برای جلوگیری از خراب شدن UTF-8 فارسی
        if (str_starts_with($text, 'B64:')) {
            $decoded = base64_decode(substr($text, 4), true);
            $text = is_string($decoded) ? $decoded : '';
        }
        if ($text === '' || $text === '[]') {
            return [];
        }
        $data = json_decode($text, true);
        if ($data === null && $text !== 'null') {
            $data = json_decode('[' . $text . ']', true);
        }
        if (!is_array($data)) {
            return [];
        }
        if ($data !== [] && array_keys($data) !== range(0, count($data) - 1)) {
            $data = [$data];
        }
        $rows = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $norm = [];
            $columns = [];
            foreach ($row as $k => $v) {
                $key = food_ticket_normalize_column((string) $k);
                if (is_string($v)) {
                    $v = food_ticket_utf8($v);
                }
                $norm[$key] = $v;
                $columns[$key] = (string) $k;
            }
            $uid = food_ticket_row_value($norm, ['L_UID', 'LUID', 'UID', 'PersonnelCode', 'UserCode', 'EmpCode', 'UserID']);
            $card = food_ticket_row_value($norm, ['C_Card', 'Card', 'CardUID', 'CardNo', 'CardNumber', 'RFID', 'C_CARD']);
            $date = food_ticket_row_value($norm, ['C_Date', 'C_DATE', 'PunchDate', 'Date']);
            $time = food_ticket_row_value($norm, ['C_Time', 'C_TIME', 'PunchTime', 'Time']);
            $rows[] = [
                'row' => $norm,
                'columns' => $columns,
                'uid' => food_ticket_code($uid ?? ''),
                'card' => food_ticket_code($card ?? ''),
                'date_raw' => $date,
                'time_raw' => $time,
            ];
        }
        return $rows;
    }
    $hint = (string) ($handle['odbc_hint'] ?? '');
    throw new RuntimeException(
        'خواندن Access ناموفق بود. ' . ($text !== '' ? $text : 'exit ' . $code) .
        ($hint !== '' ? ' | ODBC PHP: ' . $hint : '') .
        ' — راه‌حل: 1) Microsoft Access Database Engine 2016 Redistributable (هم‌معماری با PHP، معمولاً 64-bit) را نصب کنید 2) یا در php.ini مقدار extension=odbc را فعال کنید 3) مسیر فایل .mdb/.accdb را از همان سرور بررسی کنید.'
    );
}


function food_ticket_access_delete_powershell(array $handle, string $sql, array $params): bool
{
    $result = food_ticket_access_delete_powershell_ex($handle, $sql, $params);
    return !empty($result['ok']);
}

/**
 * حذف دقیق از Access با PowerShell و برگرداندن نتیجهٔ واقعی:
 * matched = تعداد ردیف‌های تطبیق‌یافته، affected = تعداد ردیف‌های حذف‌شده.
 * ⚠️ نسخهٔ قبلی در حالت «۰ تطبیق» هم ok=true برمی‌گرداند و همین باعث می‌شد
 * سامانه حذف را «موفق» گزارش کند در حالی که رکورد SOURCE_TABLE دست‌نخورده مانده بود.
 *
 * @return array{ok:bool,matched:int,affected:int,error:string}
 */
function food_ticket_access_delete_powershell_ex(array $handle, string $sql, array $params, bool $allowMany = false): array
{
    $fail = static function (string $msg, int $matched = -1, int $affected = 0): array {
        return ['ok' => false, 'matched' => $matched, 'affected' => $affected, 'error' => $msg];
    };
    $payload = base64_encode((string) json_encode([
        'path' => (string) ($handle['path'] ?? ''),
        'password' => (string) ($handle['password'] ?? ''),
        'sql' => $sql,
        'params' => array_values($params),
        // allowMany فقط برای پاک‌سازی دسته‌ای با کلید اصلی (هر شرط یکتا) استفاده می‌شود.
        'allowMany' => $allowMany,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $ps = <<<'PS'
$ErrorActionPreference = 'Stop'
$data = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__FT_PAYLOAD__')) | ConvertFrom-Json
$match = [regex]::Match([string]$data.sql, '^\s*DELETE\s+FROM\s+(.+?)\s+WHERE\s+(.+)\s*$', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
if (-not $match.Success) { [Console]::Error.WriteLine('Unsupported exact-delete statement'); exit 2 }
$countSql = 'SELECT COUNT(*) FROM ' + $match.Groups[1].Value + ' WHERE ' + $match.Groups[2].Value
$providers = @('Microsoft.ACE.OLEDB.16.0', 'Microsoft.ACE.OLEDB.12.0', 'Microsoft.Jet.OLEDB.4.0')
$errors = @()
foreach ($provider in $providers) {
  $connection = $null
  try {
    $builder = New-Object System.Data.OleDb.OleDbConnectionStringBuilder
    $builder.Provider = $provider
    $builder.DataSource = [string]$data.path
    $builder['Persist Security Info'] = $false
    if ([string]$data.password -ne '') { $builder['Jet OLEDB:Database Password'] = [string]$data.password }
    $connection = New-Object System.Data.OleDb.OleDbConnection($builder.ConnectionString)
    $connection.Open()
    $command = $connection.CreateCommand()
    $command.CommandText = $countSql
    $matches = [int]$command.ExecuteScalar()
    if ($matches -eq 0) { @{ ok = $false; matched = 0; affected = 0; error = 'NO_MATCH' } | ConvertTo-Json -Compress; exit 0 }
    if ($matches -gt 1 -and -not [bool]$data.allowMany) { @{ ok = $false; matched = $matches; affected = 0; error = "MATCHED $matches rows" } | ConvertTo-Json -Compress; exit 0 }
    $command.CommandText = [string]$data.sql
    if (@($data.params).Count -gt 0) { foreach ($value in @($data.params)) {
      $parameter = $command.Parameters.Add('?', [System.Data.OleDb.OleDbType]::VarWChar, 255)
      if ($null -eq $value) {
        $parameter.Value = [DBNull]::Value
      } elseif ($value -is [bool]) {
        $parameter.OleDbType = [System.Data.OleDb.OleDbType]::Boolean
        $parameter.Value = $value
      } elseif ($value -is [int] -or $value -is [long]) {
        $parameter.OleDbType = [System.Data.OleDb.OleDbType]::BigInt
        $parameter.Value = $value
      } elseif ($value -is [double] -or $value -is [decimal]) {
        $parameter.OleDbType = [System.Data.OleDb.OleDbType]::Double
        $parameter.Value = $value
      } elseif ($value -is [string] -and $value -match '^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$') {
        try {
          $parameter.OleDbType = [System.Data.OleDb.OleDbType]::Date
          $parameter.Value = [DateTime]::Parse($value, [Globalization.CultureInfo]::InvariantCulture)
        } catch {
          $parameter.OleDbType = [System.Data.OleDb.OleDbType]::VarWChar
          $parameter.Value = $value
        }
      } else {
        $parameter.Value = [string]$value
      }
    }
    }
    $affected = $command.ExecuteNonQuery()
    if ([bool]$data.allowMany) {
      if ($affected -lt 1) { @{ ok = $false; matched = $matches; affected = $affected; error = 'DELETE affected no rows' } | ConvertTo-Json -Compress; exit 0 }
    } elseif ($affected -ne 1) {
      throw [Exception]::new("DELETE affected $affected rows; expected exactly one")
    }
    @{ ok = $true; matched = $matches; affected = $affected } | ConvertTo-Json -Compress
    exit 0
  } catch {
    $errors += ($provider + ': ' + $_.Exception.Message)
  } finally {
    if ($connection) { $connection.Dispose() }
  }
}
[Console]::Error.WriteLine(($errors -join ' | '))
exit 2
PS;
    $ps = str_replace('__FT_PAYLOAD__', $payload, $ps);
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ft_del_' . bin2hex(random_bytes(4)) . '.ps1';
    if (@file_put_contents($tmp, $ps, LOCK_EX) === false) {
        error_log('Food ticket PowerShell delete script could not be written.');
        return $fail('نوشتن اسکریپت PowerShell حذف ممکن نشد.');
    }
    try {
        $output = [];
        $code = 0;
        @exec('powershell -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            error_log('Food ticket Access DELETE failed: ' . implode(' ', $output));
            return $fail('اجرای PowerShell با کد ' . $code . ' پایان یافت: ' . implode(' ', $output));
        }
        $result = json_decode(trim(implode("\n", $output)), true);
        if (!is_array($result)) {
            error_log('Food ticket Access DELETE returned unparsable output: ' . implode(' ', $output));
            return $fail('خروجی PowerShell قابل خواندن نبود.');
        }
        return [
            'ok' => !empty($result['ok']),
            'matched' => array_key_exists('matched', $result) ? (int) $result['matched'] : -1,
            'affected' => array_key_exists('affected', $result) ? (int) $result['affected'] : 0,
            'error' => (string) ($result['error'] ?? ''),
        ];
    } finally {
        @unlink($tmp);
    }
}

function food_ticket_normalize_column(string $name): string
{
    return strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '_', trim($name)));
}

function food_ticket_row_value(array $row, array $names): mixed
{
    foreach ($names as $name) {
        $key = food_ticket_normalize_column($name);
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
    }
    return null;
}


function food_ticket_access_rows($connection, string $table, int $limit = 500, ?string $orderSql = null, ?string $whereSql = null, bool $strict = false): array
{
    if (food_ticket_is_ps_access($connection)) {
        return food_ticket_access_rows_powershell($connection, $table, $limit, $orderSql, $whereSql);
    }
    $limit = max(1, min(50000, $limit));
    $sql = 'SELECT TOP ' . $limit . ' * FROM ' . food_ticket_ident($table);
    if ($whereSql) {
        $sql .= ' WHERE ' . $whereSql;
    }
    if ($orderSql) {
        $sql .= ' ORDER BY ' . $orderSql;
    }
    $result = @odbc_exec($connection, $sql);
    if (!$result && $strict) {
        // حالت سخت‌گیر: خطای فیلتر/مرتب‌سازی باید به فراخواننده برسد، نه اینکه ردیف‌های فیلترنشده برگردد.
        throw new RuntimeException('کوئری Access ناموفق بود: ' . (string) odbc_errormsg($connection) . ' | SQL: ' . $sql);
    }
    if (!$result) {
        // بدون ORDER/WHERE دوباره تلاش (بعضی جداول لینک‌شده محدودند)
        $result = @odbc_exec($connection, 'SELECT TOP ' . $limit . ' * FROM ' . food_ticket_ident($table));
    }
    if (!$result) {
        throw new RuntimeException('خواندن جدول Access ناموفق بود: ' . (string) odbc_errormsg($connection));
    }
    $rows = [];
    $fieldNames = [];
    $fieldTypes = [];
    for ($i = 1; $i <= odbc_num_fields($result); $i++) {
        $fieldNames[] = (string) odbc_field_name($result, $i);
        $fieldTypes[food_ticket_normalize_column((string) odbc_field_name($result, $i))] = strtolower((string) @odbc_field_type($result, $i));
    }
    while ($raw = odbc_fetch_array($result)) {
        $row = [];
        $columns = [];
        foreach ($fieldNames as $field) {
            $key = food_ticket_normalize_column($field);
            $val = $raw[$field] ?? null;
            if (is_string($val)) {
                $val = food_ticket_utf8($val);
            }
            $row[$key] = $val;
            $columns[$key] = $field;
        }
        $uid = food_ticket_row_value($row, ['L_UID', 'LUID', 'UID', 'PersonnelCode', 'UserCode', 'EmpCode', 'UserID']);
        $card = food_ticket_row_value($row, ['C_Card', 'Card', 'CardUID', 'CardNo', 'CardNumber', 'RFID', 'C_CARD']);
        $date = food_ticket_row_value($row, ['C_Date', 'C_DATE', 'PunchDate', 'Date']);
        $time = food_ticket_row_value($row, ['C_Time', 'C_TIME', 'PunchTime', 'Time']);
        $rows[] = [
            'row' => $row,
            'columns' => $columns,
            'types' => $fieldTypes,
            'uid' => food_ticket_code($uid ?? ''),
            'card' => food_ticket_code($card ?? ''),
            'date_raw' => $date,
            'time_raw' => $time,
        ];
    }
    return $rows;
}

function food_ticket_normalize_digits(string $value): string
{
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($persian, $latin, $value);
}

/**
 * نرمال‌سازی «ورودی تاریخ از رابط کاربری» به Y-m-d میلادی.
 * هم شمسی (۱۴۰۵/۰۷/۱۴ یا ۱۴۰۵۰۷۱۴) و هم میلادی (2026-10-06 یا 20261006) پذیرفته می‌شود.
 * قالب انتقال داده با API همان Y-m-d میلادی می‌ماند (هیچ API‌ای عوض نشده است).
 *
 * @return string|null Y-m-d میلادی یا null اگر قابل تشخیص نباشد
 */
function food_ticket_iso_date_input(mixed $value): ?string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }
    $raw = trim(food_ticket_normalize_digits((string) ($value ?? '')));
    if ($raw === '') {
        return null;
    }
    // ⚠️ دام: «1405-07-14» شمسی است، نه ۱۴۰۵ میلادی. تشخیص بر اساس بازهٔ سال انجام می‌شود
    // (سال‌های ۱۲۰۰–۱۶۰۰ شمسی، سال‌های ۱۷۰۰–۲۱۰۰ میلادی).
    if (preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/', $raw, $m) === 1) {
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $da = (int) $m[3];
        if ($y >= 1700 && $y <= 2100 && checkdate($mo, $da, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $da);
        }
        if ($y >= 1200 && $y <= 1600) {
            return food_ticket_jalali_to_gregorian($y, $mo, $da);
        }
        return null;
    }
    return food_ticket_parse_date($raw);
}

/**
 * تبدیل تاریخ شمسی به میلادی — مستقل از bootstrap.
 * همان الگوریتم تقویم مشترک سامانه (bootstrap.php → jalali_to_gregorian و
 * assets/jalali-calendar.js → j2g) تا همه‌جا یک نتیجه بدهد. این نسخهٔ محلی فقط
 * زمانی استفاده می‌شود که تابع سامانه در دسترس نباشد (مثلاً Worker مستقل).
 *
 * @return string|null Y-m-d میلادی یا null اگر ورودی معتبر نباشد
 */
function food_ticket_jalali_to_gregorian(int $jy, int $jm, int $jd): ?string
{
    if ($jy < 1200 || $jy > 1600 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
        return null;
    }
    $jy2 = $jy - 979;
    $jm2 = $jm - 1;
    $jd2 = $jd - 1;
    $jdn = 365 * $jy2 + intdiv($jy2, 33) * 8 + intdiv(($jy2 % 33) + 3, 4);
    for ($i = 0; $i < $jm2; $i++) {
        $jdn += $i < 6 ? 31 : 30;
    }
    $jdn += $jd2;
    $gdn = $jdn + 79;
    $gy = 1600 + 400 * intdiv($gdn, 146097);
    $gdn %= 146097;
    $leap = true;
    if ($gdn >= 36525) {
        $gdn--;
        $gy += 100 * intdiv($gdn, 36524);
        $gdn %= 36524;
        if ($gdn >= 365) {
            $gdn++;
        } else {
            $leap = false;
        }
    }
    $gy += 4 * intdiv($gdn, 1461);
    $gdn %= 1461;
    if ($gdn >= 366) {
        $leap = false;
        $gdn--;
        $gy += intdiv($gdn, 365);
        $gdn %= 365;
    }
    $gd = $gdn + 1;
    $monthDays = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 1;
    while ($gm <= 12 && $gd > $monthDays[$gm]) {
        $gd -= $monthDays[$gm];
        $gm++;
    }
    if ($gm > 12 || !checkdate($gm, $gd, $gy)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/**
 * تبدیل تاریخ خام Access به Y-m-d میلادی.
 * پشتیبانی: میلادی، شمسی (1403/07/08 یا 14030708)، رقم فارسی، سریال OLE.
 */
function food_ticket_parse_date(mixed $value): ?string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }
    if ($value === null || $value === '') {
        return null;
    }

    $tryJalali = static function (int $y, int $m, int $d): ?string {
        if ($y < 1200 || $y > 1500 || $m < 1 || $m > 12 || $d < 1 || $d > 31) {
            return null;
        }
        if (!function_exists('jalali_to_gregorian')) {
            // Worker/CLI مستقل: نسخهٔ محلی همان الگوریتم سامانه
            return food_ticket_jalali_to_gregorian($y, $m, $d);
        }
        [$gy, $gm, $gd] = jalali_to_gregorian($y, $m, $d);
        if (checkdate($gm, $gd, $gy)) {
            return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
        }
        return null;
    };

    // عدد خالص (int/float از Access)
    if (is_numeric($value) && !is_string($value)) {
        $n = (float) $value;
        // سریال OLE فقط در بازه معقول تاریخ‌های مدرن (~1955 تا ~2040)
        if ($n > 20000 && $n < 60000) {
            $dt = new DateTimeImmutable('1899-12-30');
            return $dt->modify('+' . (int) $n . ' days')->format('Y-m-d');
        }
        // yyyymmdd عددی: میلادی یا شمسی
        $digits = sprintf('%08d', (int) $n);
        if (strlen($digits) >= 8) {
            $y = (int) substr($digits, 0, 4);
            $m = (int) substr($digits, 4, 2);
            $d = (int) substr($digits, 6, 2);
            if ($y >= 1990 && $y <= 2100 && checkdate($m, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $m, $d);
            }
            $jalali = $tryJalali($y, $m, $d);
            if ($jalali !== null) {
                return $jalali;
            }
        }
    }

    $value = food_ticket_normalize_digits(trim((string) $value));
    // جداکننده شمسی رایج: 1403/7/8 یا 1403-07-08
    if (preg_match('/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})/', $value, $m)) {
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        if ($y >= 1990 && $y <= 2100 && checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        $jalali = $tryJalali($y, $mo, $d);
        if ($jalali !== null) {
            return $jalali;
        }
    }

    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (strlen($digits) === 8) {
        $y = (int) substr($digits, 0, 4);
        $mo = (int) substr($digits, 4, 2);
        $d = (int) substr($digits, 6, 2);
        if ($y >= 1990 && $y <= 2100 && checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        $jalali = $tryJalali($y, $mo, $d);
        if ($jalali !== null) {
            return $jalali;
        }
    }
    // yyyymm (بدون روز) را رد می‌کنیم
    foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'm/d/Y', 'Y-m-d H:i:s'] as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, substr($value, 0, strlen($fmt) + 6));
        if ($dt instanceof DateTimeImmutable) {
            $y = (int) $dt->format('Y');
            // اگر createFromFormat سال شمسی را میلادی فرض کرده، رد کن
            if ($y >= 1990 && $y <= 2100) {
                return $dt->format('Y-m-d');
            }
        }
    }
    if (strtotime($value) !== false) {
        $ts = strtotime($value);
        $y = (int) date('Y', $ts);
        if ($y >= 1990 && $y <= 2100) {
            return date('Y-m-d', $ts);
        }
    }
    return null;
}

function food_ticket_parse_time(mixed $value): ?string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('H:i:s');
    }
    if ($value === null || $value === '') {
        return null;
    }
    // OLE fraction of day
    if (is_numeric($value)) {
        $number = (float) $value;
        if ($number >= 0 && $number < 1) {
            return gmdate('H:i:s', (int) round($number * 86400));
        }
        // 130523 یا 83000 (083000 بدون صفر) → همیشه ۶ رقم
        $asInt = (int) $number;
        if ($asInt >= 0 && $asInt <= 235959) {
            $digits = sprintf('%06d', $asInt);
            $h = (int) substr($digits, 0, 2);
            $i = (int) substr($digits, 2, 2);
            $s = (int) substr($digits, 4, 2);
            if ($h <= 23 && $i <= 59 && $s <= 59) {
                return sprintf('%02d:%02d:%02d', $h, $i, $s);
            }
        }
    }
    $value = trim((string) $value);
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (strlen($digits) === 5) {
        $digits = '0' . $digits;
    }
    if (strlen($digits) === 4) {
        $digits .= '00';
    }
    if (strlen($digits) === 6) {
        $h = (int) substr($digits, 0, 2);
        $i = (int) substr($digits, 2, 2);
        $s = (int) substr($digits, 4, 2);
        if ($h <= 23 && $i <= 59 && $s <= 59) {
            return sprintf('%02d:%02d:%02d', $h, $i, $s);
        }
    }
    foreach (['H:i:s', 'H:i'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->format('H:i:s');
        }
    }
    return null;
}

function food_ticket_source_key(array $item): string
{
    $normalize = static function (mixed $value): mixed {
        if (is_bool($value) || $value === null) {
            return $value === true ? '1' : ($value === false ? '0' : '');
        }
        if (is_scalar($value)) {
            return food_ticket_normalize_digits(trim((string) $value));
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_array($v) || is_object($v)) {
                    $enc = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
                    $out[(string) $k] = is_string($enc) ? $enc : '';
                } elseif (is_bool($v) || $v === null) {
                    $out[(string) $k] = $v === true ? '1' : ($v === false ? '0' : '');
                } else {
                    $out[(string) $k] = is_scalar($v) ? (string) $v : '';
                }
            }
            return $out;
        }
        return '';
    };

    $payload = [
        $normalize($item['uid'] ?? ''),
        $normalize($item['card'] ?? ''),
        $normalize($item['date_raw'] ?? ''),
        $normalize($item['time_raw'] ?? ''),
        $normalize($item['row'] ?? []),
    ];
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
        $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
    }
    $json = json_encode($payload, $flags);
    if (!is_string($json) || $json === '') {
        $json = implode('|', [
            (string) ($item['uid'] ?? ''),
            (string) ($item['card'] ?? ''),
            is_scalar($item['date_raw'] ?? null) ? (string) $item['date_raw'] : '',
            is_scalar($item['time_raw'] ?? null) ? (string) $item['time_raw'] : '',
            (string) microtime(true),
        ]);
    }
    return hash('sha256', $json);
}

/**
 * نرمال‌سازی کد پرسنلی از Access/دستگاه:
 * 377.0 → 377 | 0377 → 377 | ۰۰۳۷۷ → 377 | " 377 " → 377
 */
function food_ticket_code(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if (is_numeric($value) && !is_string($value)) {
        return (string) (int) round((float) $value);
    }
    // کاراکترهای نامرئی (نیم‌فاصله، RLM/LRM، NBSP، BOM) و فاصله‌ها را حذف کن؛
    // در داده‌های AD/اکسل رایج‌اند و باعث می‌شوند «377» با «377‌» برابر نباشد.
    $clean = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{00A0}\s]+/u', '', (string) $value);
    $value = food_ticket_normalize_digits(trim($clean ?? (string) $value));
    if (preg_match('/^(\d+)\.0+$/', $value, $m)) {
        $value = $m[1];
    }
    if (preg_match('/^\d+$/', $value)) {
        $value = ltrim($value, '0');
        if ($value === '') {
            $value = '0';
        }
    }
    return $value;
}

function food_ticket_digits(mixed $value): string
{
    return preg_replace('/\D+/', '', food_ticket_normalize_digits((string) $value)) ?? '';
}

function food_ticket_find_user(string $personnelCode): ?array
{
    if (function_exists('food_ticket_find_user_strict')) {
        return food_ticket_find_user_strict($personnelCode);
    }
    $code = food_ticket_code($personnelCode);
    if ($code === '' || $code === '0') {
        return null;
    }
    $query = db()->prepare(
        'SELECT id, full_name, employee_number, national_code, is_active FROM users
         WHERE employee_number = ? OR employee_number = ? OR username = ? LIMIT 1'
    );
    $query->execute([$personnelCode, $code, $code]);
    return $query->fetch() ?: null;
}


function food_ticket_guest_cards(array $config): array
{
    if (db_table_exists('food_ticket_guest_cards')) {
        try {
            $rows = db()->query('SELECT card_uid FROM food_ticket_guest_cards WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
            $hasRows = (int) db()->query('SELECT COUNT(*) FROM food_ticket_guest_cards')->fetchColumn() > 0;
            if ($hasRows) {
                return array_values(array_unique(array_filter(array_map(static function (mixed $card): string {
                    return strtoupper(preg_replace('/[\s:-]+/', '', trim((string) $card)) ?? '');
                }, $rows))));
            }
        } catch (Throwable) {
            // Fall back to the legacy config while an older installation is being upgraded.
        }
    }
    $raw = str_replace([';', '،'], ',', (string) ($config['guest_card_uids'] ?? ''));
    $cards = [];
    foreach (explode(',', $raw) as $card) {
        $card = strtoupper(preg_replace('/[\s:-]+/', '', trim($card)) ?? '');
        if ($card !== '') {
            $cards[] = $card;
        }
    }
    return array_values(array_unique($cards));
}

/** کلید یکتای شمارهٔ کارت: حذف فاصله/خط‌تیره/دونقطه/کاراکتر نامرئی، حروف بزرگ، حذف صفرهای ابتدایی شمارهٔ کاملاً عددی */
function food_ticket_card_key(string $card): string
{
    $n = strtoupper((string) preg_replace('/[\s:\-\x{200B}-\x{200F}\x{202A}-\x{202E}\x{FEFF}\x{00A0}]+/u', '', trim($card)));
    if ($n !== '' && preg_match('/^\d+$/', $n)) {
        $n = ltrim($n, '0');
        if ($n === '') {
            $n = '0';
        }
    }
    return $n;
}

/**
 * برابری دو شمارهٔ کارت.
 * قبلاً «پایان‌یافتن با» در هر دو جهت کافی بود؛ یعنی کارت مهمانِ کوتاه (مثل «1» یا «80») با هر کارت/UID دیگری
 * که به همان ختم می‌شد (مثل 45645E1 یا 380) برابر حساب می‌شد و پرسنل «مهمان» شناخته می‌شدند.
 * اکنون فقط برابریِ کامل، یا پسوند مشترک با حداقل ۶ نویسه (برای دستگاه‌هایی که بایت پیشوند اضافه می‌کنند).
 */
function food_ticket_card_equals(string $a, string $b): bool
{
    $a = food_ticket_card_key($a);
    $b = food_ticket_card_key($b);
    if ($a === '' || $b === '' || $a === '0' || $b === '0') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    return min(strlen($a), strlen($b)) >= 6 && (str_ends_with($a, $b) || str_ends_with($b, $a));
}

function food_ticket_guest_card(string $card, array $config): ?array
{
    if (food_ticket_card_key($card) === '') {
        return null;
    }
    if (db_table_exists('food_ticket_guest_cards')) {
        try {
            $rows = db()->query('SELECT id, card_uid, guest_name, daily_limit FROM food_ticket_guest_cards WHERE is_active = 1 ORDER BY id')->fetchAll();
            foreach ($rows as $row) {
                if (food_ticket_card_equals($card, (string) ($row['card_uid'] ?? ''))) {
                    return $row;
                }
            }
            if ((int) db()->query('SELECT COUNT(*) FROM food_ticket_guest_cards')->fetchColumn() > 0) {
                return null;
            }
        } catch (Throwable) {
            // Fall back to the legacy config while an older installation is being upgraded.
        }
    }
    foreach (food_ticket_guest_cards($config) as $guest) {
        if (food_ticket_card_equals($card, (string) $guest)) {
            return ['card_uid' => $guest, 'guest_name' => 'مهمان', 'daily_limit' => 0];
        }
    }
    return null;
}

function food_ticket_is_guest(string $card, array $config): bool
{
    return food_ticket_guest_card($card, $config) !== null;
}

function food_ticket_existing_source(string $sourceKey): ?array
{
    $query = db()->prepare('SELECT * FROM food_ticket_events WHERE source_key = ? LIMIT 1');
    $query->execute([$sourceKey]);
    return $query->fetch() ?: null;
}

function food_ticket_existing_ticket(string $ticketKey): ?array
{
    $query = db()->prepare('SELECT * FROM food_ticket_events WHERE ticket_key = ? LIMIT 1');
    $query->execute([$ticketKey]);
    return $query->fetch() ?: null;
}

/**
 * نگاشت event_type به مقادیر مجاز ENUM جدول food_ticket_events
 * تا INSERT/UPDATE با خطای Data truncated شکست نخورد.
 */
function food_ticket_normalize_event_type(mixed $type): string
{
    $type = strtolower(trim((string) $type));
    $allowed = [
        'printed', 'print_error', 'no_food', 'unknown', 'inactive',
        'repeat', 'guest', 'guest_limit', 'config_error',
    ];
    if (in_array($type, $allowed, true)) {
        return $type;
    }
    // نگاشت انواع داخلی موتور که در ENUM نیستند
    return match ($type) {
        'outside_meal' => 'no_food',
        // «ردیف قدیمی/خارج از بازه» خطای پیکربندی است، نه «سفارش نداشتن»؛
        // قبلاً به no_food نگاشت می‌شد و در گزارش با «بدون غذا» اشتباه می‌شد.
        'skipped_old' => 'config_error',
        'unknown_uid' => 'unknown',
        'duplicate', 'retry', 'retry_exhausted', 'print' => 'printed',
        default => 'config_error',
    };
}

function food_ticket_normalize_print_status(mixed $status): string
{
    $status = strtolower(trim((string) $status));
    $allowed = ['not_printed', 'pending', 'printing', 'printed', 'print_error', 'failed', 'held_absent'];
    if (in_array($status, $allowed, true)) {
        return $status;
    }
    return 'not_printed';
}

function food_ticket_add_event(array $event): int
{
    $columns = '(source_key, source_uid, source_card, punch_date, punch_time, personnel_code, user_id, national_code, full_name, food_type, ticket_key, event_type, print_status, last_error, source_payload, processed_at';
    $values = 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()';
    $args = [
        $event['source_key'], $event['source_uid'] ?? null, $event['source_card'] ?? null,
        $event['punch_date'] ?? null, $event['punch_time'] ?? null, $event['personnel_code'] ?? null,
        $event['user_id'] ?? null, $event['national_code'] ?? null, $event['full_name'] ?? null,
        $event['food_type'] ?? null, $event['ticket_key'] ?? null,
        food_ticket_normalize_event_type($event['event_type'] ?? 'config_error'),
        food_ticket_normalize_print_status($event['print_status'] ?? 'not_printed'),
        $event['last_error'] ?? null, $event['source_payload'] ?? null,
    ];
    // وضعیت تحویل food جدا از وضعیت چاپ نگه داشته می‌شود (اگر ستون ساخته شده باشد).
    if (function_exists('food_ticket_delivery_supported') && food_ticket_delivery_supported()) {
        $columns .= ', delivery_status';
        $values .= ', ?';
        $deliveryStatus = (string) ($event['delivery_status'] ?? 'pending');
        $args[] = in_array($deliveryStatus, ['pending', 'delivered'], true) ? $deliveryStatus : 'pending';
    }
    $query = db()->prepare('INSERT INTO food_ticket_events ' . $columns . ') ' . $values . ')');
    $query->execute($args);
    return (int) db()->lastInsertId();
}

function food_ticket_update_event(int $id, string $status, string $type, ?string $error = null): void
{
    db()->prepare('UPDATE food_ticket_events SET print_status = ?, event_type = ?, print_attempts = print_attempts + 1, last_error = ?, processed_at = NOW() WHERE id = ?')
        ->execute([
            food_ticket_normalize_print_status($status),
            food_ticket_normalize_event_type($type),
            $error,
            $id,
        ]);
}

function food_ticket_source_delete_where(array $item): ?string
{
    $uid = trim((string) ($item['uid'] ?? ''));
    $card = trim((string) ($item['card'] ?? ''));
    $date = $item['date_raw'] ?? null;
    $time = $item['time_raw'] ?? null;
    if (($uid === '' && $card === '') || $date === null || $date === '' || $time === null || $time === '') {
        error_log('[food-ticket] delete_source: exact uid/card/date/time identity is incomplete');
        return null;
    }

    $numericTypes = ['integer', 'int', 'smallint', 'tinyint', 'bigint', 'counter', 'long', 'double', 'float', 'real', 'numeric', 'decimal', 'currency', 'byte', 'single', 'autonumber'];
    $skipTypes = ['longvarchar', 'longvarbinary', 'varbinary', 'binary', 'memo', 'ole', 'timestamp', 'datetime', 'date', 'time', 'bit', 'boolean', 'yesno'];

    $isNumericLiteral = static fn (string $v): bool => (bool) preg_match('/^-?\d+(\.\d+)?$/', $v);

    $row = is_array($item['row'] ?? null) ? $item['row'] : [];
    $columns = is_array($item['columns'] ?? null) ? $item['columns'] : [];
    $types = is_array($item['types'] ?? null) ? $item['types'] : [];
    $parts = [];
    foreach ($row as $key => $value) {
        if (!is_scalar($value) && $value !== null) {
            continue;
        }
        $column = (string) ($columns[$key] ?? $key);
        if ($column === '') {
            continue;
        }
        $type = (string) ($types[$key] ?? '');
        // ODBC ممکن است مقدارها را به‌صورت رشته برگرداند؛ شرط با نوع واقعی ستون ساخته می‌شود.
        // ستون‌های memo/باینری در شناسهٔ دقیق وارد نمی‌شوند.
        if ($type !== '' && in_array($type, $skipTypes, true) && !food_ticket_source_is_temporal((string) $key, $type)) {
            continue;
        }
        $isTemporal = food_ticket_source_is_temporal((string) $key, $type);
        $identifier = food_ticket_ident($column);
        if ($value === null) {
            $parts[] = $identifier . ' IS NULL';
            continue;
        }
        if (is_bool($value)) {
            continue;
        }
        if (is_int($value)) {
            $parts[] = $identifier . ' = ' . $value;
            continue;
        }
        if (is_float($value)) {
            $parts[] = $identifier . ' = ' . sprintf('%.14G', $value);
            continue;
        }
        $text = (string) $value;
        if ($isTemporal) {
            // مسیر PowerShell نوع ستون را برنمی‌گرداند؛ ستون تاریخ/زمان با قالب Access (#...#) نوشته می‌شود.
            // در Jet، مقایسهٔ ستون تاریخ با رشتهٔ ساده تطبیق نمی‌دهد و دلیل «۰ تطبیق» همین بود.
            $parts[] = $identifier . ' = ' . food_ticket_source_sql_literal($text);
            continue;
        }
        $numberLiteral = trim(food_ticket_normalize_digits($text));
        $numericType = food_ticket_source_type_kind($type) === 'numeric' || in_array($type, $numericTypes, true);
        $useNumber = $numericType && $isNumericLiteral($numberLiteral);
        if ($type !== '' && $numericType && !$useNumber) {
            continue; // مقدار غیرعددی در ستون عددی؛ از شرط حذف
        }
        $parts[] = $useNumber
            ? $identifier . ' = ' . $numberLiteral
            : $identifier . " = '" . str_replace("'", "''", $text) . "'";
    }
    return $parts ? implode(' AND ', $parts) : null;
}

/**
 * ستون کلید اصلی جدول SOURCE_TABLE را از خود ردیف کشف می‌کند (اگر وجود داشته باشد).
 * حذف با کلید اصلی همیشه دقیق است و به نوع/لحن متن یا قالب تاریخ وابسته نیست.
 *
 * @return array{column:string,value:int,where:string}|null
 */
function food_ticket_source_primary_key(array $item): ?array
{
    $row = is_array($item['row'] ?? null) ? $item['row'] : [];
    $columns = is_array($item['columns'] ?? null) ? $item['columns'] : [];
    if (!$row) {
        return null;
    }
    $candidates = [
        'id', 'source_row_id', 'source_row_id', 'recordid', 'record_id', 'rowid', 'row_id',
        'pk', 'pkey', 'primarykey', 'primary_key', 'autoid', 'auto_id', 'identity', 'counter',
    ];
    foreach ($candidates as $candidate) {
        if (!array_key_exists($candidate, $row)) {
            continue;
        }
        $value = $row[$candidate];
        if ($value === null || is_bool($value) || is_array($value)) {
            continue;
        }
        $text = trim(food_ticket_normalize_digits((string) $value));
        if ($text === '' || preg_match('/^\d+$/', $text) !== 1) {
            continue;
        }
        $column = (string) ($columns[$candidate] ?? $candidate);
        if ($column === '') {
            continue;
        }
        return [
            'column' => $column,
            'value' => (int) $text,
            'where' => food_ticket_ident($column) . ' = ' . (int) $text,
        ];
    }
    return null;
}

/** نوع دادهٔ گزارش‌شده از ODBC/Access را به یک گروه قابل استفاده برای SQL تبدیل می‌کند. */
function food_ticket_source_type_kind(string $type): string
{
    $type = strtolower(trim($type));
    if ($type === '') {
        return 'unknown';
    }
    // اول تاریخ/زمان، چون برخی برچسب‌ها ممکن است هم‌زمان واژه‌های مشابه داشته باشند.
    if (preg_match('/timestamp|datetime|dbdate|dbtime|date|time/', $type) === 1) {
        return 'temporal';
    }
    // متن باید پیش از numeric بررسی شود؛ مثلاً LONGVARCHAR متن است، نه عدد LONG.
    if (preg_match('/char|text|string|memo|binary|blob|guid|uuid/', $type) === 1) {
        return 'text';
    }
    if (preg_match('/bit|bool|yes.?no/', $type) === 1) {
        return 'boolean';
    }
    if (preg_match('/int|long|counter|autonumber|numeric|number|decimal|currency|double|float|real|byte/', $type) === 1) {
        return 'numeric';
    }
    return 'unknown';
}

/** آیا ستون، تاریخ/زمان است؟ نوع صریح ستون درایور بر نام ستون اولویت دارد. */
function food_ticket_source_is_temporal(string $columnKey, string $type): bool
{
    $kind = food_ticket_source_type_kind($type);
    if ($kind === 'temporal') {
        return true;
    }
    if ($kind !== 'unknown') {
        // برای C_Date/C_Time از نوع Short Text نباید literal تاریخ #...# ساخته شود.
        return false;
    }
    return in_array($columnKey, [
        'c_date', 'c_time', 'c_datetime', 'c_timestamp', 'punchdate', 'punchtime', 'punch_date', 'punch_time',
        'date', 'time', 'datetime', 'created_at', 'updated_at', 'insert_date', 'insert_time',
    ], true);
}

/** یک کلید تاریخ/زمان را به شکل «#YYYY-MM-DD HH:MM:SS#» برای Jet/Access می‌نویسد (کوتاه‌ترین شکل ممکن). */
function food_ticket_source_time_literal(string $value): string
{
    $text = trim(food_ticket_normalize_digits($value));
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/', $text, $m) === 1) {
        if ($m[4] === '00' && $m[5] === '00' && $m[6] === '00') {
            return '#' . $m[1] . '-' . $m[2] . '-' . $m[3] . ' 00:00:00#';
        }
        return '#' . $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] . ':' . $m[5] . ':' . $m[6] . '#';
    }
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $text) === 1) {
        // ستون زمانی در Access به‌صورت تاریخ پایه ۱۸۹۹ ذخیره می‌شود؛ همان مقدار کامل نوشته می‌شود.
        return '#1899-12-30 ' . $text . '#';
    }
    return $text;
}

/**
 * نوشتن یک مقدار به‌شکل literal امن برای Jet/Access:
 * مقدار استاندارد تاریخ/زمان → #...# ، عدد → بدون نقل‌قول ، بقیه → رشتهٔ نقل‌قول‌شده.
 */
function food_ticket_source_sql_literal(string $text): string
{
    $literal = food_ticket_source_time_literal($text);
    if (str_starts_with($literal, '#')) {
        return $literal;
    }
    $trimmed = trim($text);
    if (preg_match('/^-?\d+(\.\d+)?$/', $trimmed) === 1) {
        return $trimmed;
    }
    return "'" . str_replace("'", "''", $text) . "'";
}

/** Literal برای ستون مشخص: نوعِ واقعی ستون تعیین می‌کند متن، عدد یا تاریخ فرستاده شود. */
function food_ticket_source_column_sql_literal(string $text, string $columnKey, string $type): string
{
    $kind = food_ticket_source_type_kind($type);
    if ($kind === 'temporal' || ($kind === 'unknown' && food_ticket_source_is_temporal($columnKey, $type))) {
        return food_ticket_source_sql_literal($text);
    }
    if ($kind === 'numeric') {
        $number = trim(food_ticket_normalize_digits($text));
        if (preg_match('/^-?\d+(\.\d+)?$/', $number) === 1) {
            return $number;
        }
    }
    // Short Text، حتی اگر مقدارش شبیه تاریخ یا عدد باشد، باید رشته بماند.
    return "'" . str_replace("'", "''", $text) . "'";
}

/**
 * فهرست شرط‌های حذف، به‌ترتیب اولویت. اولین شرطی که دقیقاً یک ردیف تطبیق کند، حذف می‌شود.
 *
 * ۱) کلید اصلی (دقیق‌ترین و مستقل از نوع ستون‌ها)
 * ۲) تطبیق کامل ردیف (همهٔ ستون‌ها با نوع درست؛ ستون‌های memo/باینری و تاریخ‌ها با قالب Access)
 * ۳) شناسهٔ کمینه: uid (و کارت در صورت وجود) + تاریخ + ساعت
 *
 * @return array{0:string,1:string}[] هر عضو: [برچسب، WHERE]
 */
function food_ticket_source_delete_candidates(array $item): array
{
    $out = [];

    $pk = food_ticket_source_primary_key($item);
    if ($pk !== null) {
        $out[] = ['primary_key:' . $pk['column'], $pk['where']];
    }

    $exact = food_ticket_source_delete_where($item);
    if ($exact !== null) {
        $out[] = ['exact_row', $exact];
    }

    $minimal = food_ticket_source_minimal_where($item);
    if ($minimal !== null) {
        $out[] = ['uid_date_time', $minimal];
    }

    return $out;
}

/** شناسهٔ کمینه: ستون کد پرسنلی/L_UID (و کارت) به‌همراه تاریخ و ساعت. */
function food_ticket_source_minimal_where(array $item): ?string
{
    $uid = trim((string) ($item['uid'] ?? ''));
    $card = trim((string) ($item['card'] ?? ''));
    $date = $item['date_raw'] ?? null;
    $time = $item['time_raw'] ?? null;
    if (($uid === '' && $card === '') || $date === null || $date === '' || $time === null || $time === '') {
        return null;
    }
    $row = is_array($item['row'] ?? null) ? $item['row'] : [];
    $columns = is_array($item['columns'] ?? null) ? $item['columns'] : [];
    $types = is_array($item['types'] ?? null) ? $item['types'] : [];
    $parts = [];

    $uidKey = null;
    foreach (['l_uid', 'luid', 'uid', 'personnelcode', 'usercode', 'empcode', 'userid'] as $key) {
        if (array_key_exists($key, $row)) {
            $uidKey = $key;
            break;
        }
    }
    if ($uidKey !== null && $uid !== '') {
        $uidType = (string) ($types[$uidKey] ?? '');
        $uidLiteral = food_ticket_source_column_sql_literal($uid, $uidKey, $uidType);
        $parts[] = food_ticket_ident((string) ($columns[$uidKey] ?? $uidKey)) . ' = ' . $uidLiteral;
    } elseif ($card !== '') {
        foreach (['c_card', 'card', 'carduid', 'cardno', 'cardnumber', 'rfid'] as $key) {
            if (array_key_exists($key, $row)) {
                $cardType = (string) ($types[$key] ?? '');
                $cardLiteral = food_ticket_source_column_sql_literal($card, $key, $cardType);
                $parts[] = food_ticket_ident((string) ($columns[$key] ?? $key)) . ' = ' . $cardLiteral;
                break;
            }
        }
    }
    if (!$parts) {
        return null;
    }

    $dateKey = null;
    foreach (['c_date', 'cdate', 'punchdate', 'date'] as $key) {
        if (array_key_exists($key, $row)) { $dateKey = $key; break; }
    }
    $timeKey = null;
    foreach (['c_time', 'ctime', 'punchtime', 'time'] as $key) {
        if (array_key_exists($key, $row)) { $timeKey = $key; break; }
    }
    if ($dateKey !== null) {
        $dateType = (string) ($types[$dateKey] ?? '');
        $dateLiteral = food_ticket_source_column_sql_literal((string) $date, $dateKey, $dateType);
        $parts[] = food_ticket_ident((string) ($columns[$dateKey] ?? $dateKey)) . ' = ' . $dateLiteral;
    }
    if ($timeKey !== null) {
        $timeType = (string) ($types[$timeKey] ?? '');
        $timeLiteral = food_ticket_source_column_sql_literal((string) $time, $timeKey, $timeType);
        $parts[] = food_ticket_ident((string) ($columns[$timeKey] ?? $timeKey)) . ' = ' . $timeLiteral;
    }
    return $parts ? implode(' AND ', $parts) : null;
}

/**
 * حذف ردیف مصرف‌شده از SOURCE_TABLE.
 *
 * @param array|null $out خروجی تشخیصی: strategy/deleted/reason/matched/tried
 * @return bool true فقط وقتی واقعاً یک ردیف حذف شده باشد یا حذف عمداً خاموش باشد و `cut_source_rows` = 0 نباشد.
 *              خروجی false هرگز به‌معنای «حذف شد» گزارش نمی‌شود.
 */
function food_ticket_delete_source($connection, array $item, array $config, ?array &$out = null): bool
{
    $out = is_array($out) ? $out : [];
    $out = ['strategy' => null, 'deleted' => false, 'reason' => '', 'matched' => null, 'tried' => []];
    $sourceKey = food_ticket_source_key($item);

    if (!(int) ($config['cut_source_rows'] ?? 1)) {
        $out['reason'] = 'cut_source_rows خاموش است (حذف عمداً انجام نمی‌شود).';
        $out['skipped'] = true;
        return false;
    }

    $candidates = food_ticket_source_delete_candidates($item);
    if (!$candidates) {
        $out['reason'] = 'شناسهٔ ردیف ناقص است (uid/کارت/تاریخ/ساعت یا کلید اصلی در دست نیست).';
        error_log('[food-ticket] delete_source: incomplete identity uid=' . (string) ($item['uid'] ?? '') . ' key=' . $sourceKey);
        return false;
    }

    $table = food_ticket_ident(food_ticket_source_table());
    $errors = [];
    foreach ($candidates as [$label, $where]) {
        $out['tried'][] = $label;
        $countSql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where;
        $deleteSql = 'DELETE FROM ' . $table . ' WHERE ' . $where;
        try {
            if (food_ticket_is_ps_access($connection)) {
                $result = food_ticket_access_delete_powershell_ex($connection, $deleteSql, []);
                $out['matched'] = (int) $result['matched'];
                if ((int) $result['matched'] === 0) {
                    $errors[] = $label . ': هیچ ردیفی تطبیق نکرد';
                    continue;
                }
                if ((int) $result['matched'] > 1) {
                    $errors[] = $label . ': ' . (int) $result['matched'] . ' ردیف تطبیق کرد (حذف انجام نشد)';
                    continue;
                }
                if (empty($result['ok'])) {
                    throw new RuntimeException('خطای PowerShell: ' . (string) ($result['error'] ?? 'نامشخص'));
                }
                if ((int) $result['affected'] !== 1) {
                    $errors[] = $label . ': فرمان حذف ' . (int) $result['affected'] . ' ردیف را تحت تأثیر قرار داد';
                    continue;
                }
            } else {
                $countResult = @odbc_exec($connection, $countSql);
                if ($countResult === false || !@odbc_fetch_row($countResult)) {
                    throw new RuntimeException('شمارش تطبیق SOURCE_TABLE ناموفق بود: ' . (string) @odbc_errormsg($connection));
                }
                $matches = (int) @odbc_result($countResult, 1);
                $out['matched'] = $matches;
                if ($matches === 0) {
                    $errors[] = $label . ': هیچ ردیفی تطبیق نکرد';
                    continue;
                }
                if ($matches !== 1) {
                    $errors[] = $label . ': ' . $matches . ' ردیف تطبیق کرد (حذف انجام نشد)';
                    continue;
                }
                $deleteResult = @odbc_exec($connection, $deleteSql);
                if ($deleteResult === false) {
                    throw new RuntimeException('حذف ردیف SOURCE_TABLE ناموفق بود: ' . (string) @odbc_errormsg($connection));
                }
                $affected = (int) @odbc_num_rows($deleteResult);
                if ($affected !== 1) {
                    $verify = @odbc_exec($connection, $countSql);
                    if ($verify === false || !@odbc_fetch_row($verify) || (int) @odbc_result($verify, 1) !== 0) {
                        $errors[] = $label . ': پس از حذف، ردیف هنوز در SOURCE_TABLE هست';
                        continue;
                    }
                }
            }
            $out['strategy'] = $label;
            $out['deleted'] = true;
            $out['reason'] = '';
            error_log('[food-ticket] delete_source OK strategy=' . $label . ' uid=' . (string) ($item['uid'] ?? '') . ' key=' . $sourceKey);
            return true;
        } catch (Throwable $e) {
            $errors[] = $label . ': ' . $e->getMessage();
        }
    }

    $out['reason'] = $errors ? implode(' | ', $errors) : 'حذف ناموفق بود.';
    $out['where'] = $candidates[count($candidates) - 1][1];
    error_log('[food-ticket] delete_source FAILED uid=' . (string) ($item['uid'] ?? '')
        . ' key=' . $sourceKey . ' tried=' . implode(',', $out['tried']) . ' detail=' . $out['reason']);
    return false;
}

/**
 * نتیجهٔ حذف منبع را روی خود فیش ثبت می‌کند (ستون‌های ۱.۳۳).
 * اگر ستون‌ها موجود نباشند، اول ساخته می‌شوند؛ در صورت شکست، بی‌صدا رد می‌شود
 * تا چرخهٔ پردازش هرگز به‌خاطر ثبت گزارش متوقف نشود.
 */
function food_ticket_mark_source_delete(int $eventId, bool $deleted, string $note = ''): bool
{
    if ($eventId <= 0) {
        return false;
    }
    if (function_exists('food_ticket_source_delete_supported') && !food_ticket_source_delete_supported()
        && function_exists('food_ticket_events_ensure_source_delete')) {
        food_ticket_events_ensure_source_delete();
    }
    if (function_exists('food_ticket_source_delete_supported') && !food_ticket_source_delete_supported()) {
        return false;
    }
    try {
        $stmt = db()->prepare('UPDATE food_ticket_events SET source_deleted = ?, source_deleted_at = ?, source_delete_note = ? WHERE id = ?');
        $stmt->execute([
            $deleted ? 1 : 0,
            $deleted ? date('Y-m-d H:i:s') : null,
            $note !== '' ? mb_substr($note, 0, 250) : null,
            $eventId,
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('[food-source-delete] mark failed id=' . $eventId . ': ' . $e->getMessage());
        return false;
    }
}

function food_ticket_text(array $event): string
{
    if (function_exists('food_ticket_render_from_template')) {
        return food_ticket_render_from_template($event);
    }
    $lines = [
        '==============================',
        '       فیش غذای پرسنل',
        '==============================',
        'نام: ' . ($event['full_name'] ?: '-'),
        'کد ملی: ' . ($event['national_code'] ?: '-'),
        'کد پرسنلی: ' . ($event['personnel_code'] ?: '-'),
        'نوع غذا: ' . ($event['food_type'] ?: '-'),
        'تاریخ: ' . ($event['punch_date'] ?: date('Y-m-d')),
        'ساعت: ' . ($event['punch_time'] ?: date('H:i:s')),
        '------------------------------',
        'نوش جان',
        '',
        '',
    ];
    return implode("\n", $lines) . "\n";
}

/**
 * چاپ شبکه (۹۱۰۰) یا صف/اشتراک ویندوز.
 * برای فارسی پایدار، در ویندوز از Out-Printer / copy raw استفاده می‌شود.
 */

/**
 * فهرست چاپگرهای نصب‌شده روی سرور Windows (Spooler).
 * @return list<string>
 */
function food_ticket_list_windows_printers(): array
{
    $names = [];
    if (strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
        return $names;
    }
    // کش ۲ دقیقه‌ای: هر فراخوانی تا ۳ پردازش PowerShell/wmic اجرا می‌کرد (چند ثانیه).
    $cacheFile = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/storage/printers.cache.json';
    if (is_file($cacheFile) && (time() - (int) @filemtime($cacheFile)) < 120) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached) && $cached) {
            return array_values(array_map('strval', $cached));
        }
    }
    $commands = [
        'powershell -NoProfile -Command "[Console]::OutputEncoding=[Text.UTF8Encoding]::UTF8; Get-Printer | ForEach-Object { $_.Name }"',
        'powershell -NoProfile -Command "Get-CimInstance -ClassName Win32_Printer | ForEach-Object { $_.Name }"',
        'wmic printer get name',
    ];
    foreach ($commands as $cmd) {
        $output = [];
        $code = 0;
        @exec($cmd . ' 2>&1', $output, $code);
        foreach ($output as $line) {
            $name = trim((string) $line);
            if ($name === '' || strcasecmp($name, 'Name') === 0) {
                continue;
            }
            if (preg_match('/error|exception|not recognized|access denied/i', $name)) {
                continue;
            }
            $names[] = $name;
        }
        if ($names) {
            break;
        }
    }
    $names = array_values(array_unique($names));
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    if ($names) {
        @file_put_contents($cacheFile, json_encode(array_values($names), JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return $names;
}

function food_ticket_send_to_printer(array $event, array $config): void
{
    // همان مسیر صف چاپ (قالب کامل فیش + چاپ شبکه تصویری یا صف ویندوز) تا «چاپ آزمایشی» با فیش واقعی یکی باشد.
    $config['printer_mode'] = food_ticket_normalize_printer_mode($config['printer_mode'] ?? 'tcp_raw');
    if ($config['printer_mode'] === 'tcp_raw') {
        [$config['printer_host'], $config['printer_port']] = food_ticket_normalize_printer_host((string) ($config['printer_host'] ?? ''), (int) ($config['printer_port'] ?? 9100));
        if (trim((string) $config['printer_host']) === '') {
            throw new RuntimeException('آدرس چاپگر شبکه (IP) تنظیم نشده است.');
        }
    } elseif (trim((string) ($config['printer_share'] ?? '')) === '' && trim((string) ($config['printer_host'] ?? '')) === '') {
        throw new RuntimeException('نام چاپگر ویندوز تنظیم نشده است.');
    }
    if (!function_exists('food_ticket_template') || !function_exists('food_ticket_send_raw_payload')) {
        throw new RuntimeException('ماژول چاپ بارگذاری نشده است.');
    }
    $tpl = food_ticket_template();
    $text = food_ticket_render_from_template($event, $tpl);
    food_ticket_send_raw_payload($text, $config, $tpl, $event);
}

function food_ticket_send_windows(string $text, string $printerName): void
{
    if ($printerName === '') {
        throw new RuntimeException('نام چاپگر ویندوز خالی است.');
    }
    $spoolDir = APP_ROOT . '/storage/food_ticket_spool';
    $printDir = APP_ROOT . '/storage/food_ticket_prints';
    foreach ([$spoolDir, $printDir] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
    }
    $stamp = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $file = $spoolDir . '/ticket_' . $stamp . '.txt';
    $archive = $printDir . '/fish_' . $stamp . '.txt';
    $payload = "\xEF\xBB\xBF" . $text;
    if (@file_put_contents($file, $payload) === false) {
        throw new RuntimeException('نوشتن فایل موقت چاپ ناموفق بود.');
    }
    @file_put_contents($archive, $payload);

    if (strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
        // غیر ویندوز: فقط آرشیو فایل کافی است
        return;
    }

    $psPrinter = str_replace("'", "''", $printerName);
    $psFile = str_replace("'", "''", $file);
    $isPdf = (bool) preg_match('/pdf|xps|onenote|fax/i', $printerName);

    if (str_starts_with($printerName, '\\\\')) {
        $cmd = 'cmd /c copy /b ' . escapeshellarg($file) . ' ' . escapeshellarg($printerName);
    } elseif ($isPdf) {
        // Print-to-PDF در حساب SYSTEM معمولاً دیالوگ ذخیره ندارد.
        // فایل متنی فیش در storage/food_ticket_prints ذخیره شده؛ Out-Printer هم تلاش می‌شود.
        $cmd = 'powershell -NoProfile -Command "'
            . '$ErrorActionPreference=\'Stop\';'
            . 'try { Get-Content -LiteralPath \'' . $psFile . '\' -Encoding UTF8 | Out-Printer -Name \'' . $psPrinter . '\' } '
            . 'catch { Write-Output $_.Exception.Message; exit 1 }"';
    } else {
        $cmd = 'powershell -NoProfile -Command "Get-Content -LiteralPath \'' . $psFile . '\' -Encoding UTF8 | Out-Printer -Name \'' . $psPrinter . '\'"';
    }
    $output = [];
    $code = 0;
    @exec($cmd . ' 2>&1', $output, $code);
    if ($code !== 0) {
        // برای PDF اگر Out-Printer شکست خورد، آرشیو فایل را موفقیت نسبی حساب نکن — خطا بده با مسیر فایل
        if ($isPdf) {
            throw new RuntimeException(
                'چاپ PDF از سرویس/SYSTEM معمولاً بدون پوشه خروجی پیش‌فرض کار نمی‌کند. '
                . 'فیش متنی اینجا ذخیره شد: ' . $archive . ' | ' . implode(' ', $output)
            );
        }
        throw new RuntimeException('چاپ ویندوز ناموفق: ' . implode(' ', $output) . ' | فایل: ' . $archive);
    }
}

function food_ticket_print_event(int $id, array $config, int $maxAttempts = 3): void
{
    $query = db()->prepare('SELECT * FROM food_ticket_events WHERE id = ? LIMIT 1');
    $query->execute([$id]);
    $event = $query->fetch();
    if (!$event) {
        throw new RuntimeException('رکورد فیش پیدا نشد.');
    }
    $attempts = (int) ($event['print_attempts'] ?? 0);
    if ($attempts >= $maxAttempts && in_array((string) ($event['print_status'] ?? ''), ['print_error', 'printing'], true)) {
        throw new RuntimeException('سقف تلاش چاپ برای این فیش تمام شده است.');
    }
    db()->prepare('UPDATE food_ticket_events SET print_status = "printing", print_attempts = print_attempts + 1, last_error = NULL WHERE id = ?')->execute([$id]);
    try {
        food_ticket_send_to_printer($event, $config);
        db()->prepare('UPDATE food_ticket_events SET print_status = "printed", event_type = "printed", processed_at = NOW(), last_error = NULL WHERE id = ?')->execute([$id]);
    } catch (Throwable $exception) {
        db()->prepare('UPDATE food_ticket_events SET print_status = "print_error", event_type = "print_error", processed_at = NOW(), last_error = ? WHERE id = ?')->execute([$exception->getMessage(), $id]);
        throw $exception;
    }
}

function food_ticket_process_batch(?int $requestedLimit = null): array
{
    if (function_exists('food_ticket_process_batch_v2')) {
        return food_ticket_process_batch_v2($requestedLimit);
    }
    $summary = ['processed' => 0, 'printed' => 0, 'errors' => 1, 'skipped' => 0, 'message' => 'موتور پایش بارگذاری نشد.'];
    return $summary;
}

/**
 * پاک‌سازی «ردیف‌های باقی‌مانده» در SOURCE_TABLE برای فیش‌هایی که قبلاً ثبت شده‌اند.
 *
 * چرا لازم است؟ ردیف‌هایی که در دورهٔ باگ (تطبیق صفر + گزارش موفقیت کاذب) در SOURCE_TABLE
 * انبار شده‌اند، با اصلاح کد به‌تنهایی پاک نمی‌شوند؛ این تابع همان‌ها را با شناسهٔ
 * ذخیره‌شده در source_payload پیدا و حذف می‌کند.
 *
 * اصول ایمنی: هر شرط حذف باید «دقیقاً» به ردیف مورد نظر برسد؛ اگر چند ردیف تطبیق کند
 * حذف انجام نمی‌شود (به‌جز حالت کلید اصلی که ذاتاً یکتاست و به‌صورت دسته‌ای با IN حذف می‌شود).
 *
 * @param int      $days   بازهٔ روزهای اخیر (پیش‌فرض ۷)
 * @param bool     $dryRun فقط تشخیص؛ هیچ حذفی انجام نمی‌شود
 * @param int|null $limit  سقف ردیف‌های بررسی‌شده
 * @return array{ok:bool,checked:int,matched:int,deleted:int,failed:int,skipped:int,mode:string,items:array<int,array<string,mixed>>}
 */
function food_ticket_cleanup_source_rows(int $days = 7, bool $dryRun = false, ?int $limit = null): array
{
    $config = food_ticket_config(true);
    $out = ['ok' => true, 'checked' => 0, 'matched' => 0, 'deleted' => 0, 'failed' => 0, 'skipped' => 0, 'mode' => '', 'items' => []];
    $days = max(1, min(60, $days));
    $limit = max(1, min(300, (int) ($limit ?? 60)));

    if (function_exists('food_ticket_events_ensure_source_delete')) {
        try {
            food_ticket_events_ensure_source_delete();
        } catch (Throwable $e) {
            error_log('[food-cleanup] ensure failed: ' . $e->getMessage());
        }
    }
    $hasSourceDelete = function_exists('food_ticket_source_delete_supported') && food_ticket_source_delete_supported();
    $attendancePath = trim((string) ($config['attendance_path'] ?? ''));
    if ($attendancePath === '') {
        throw new RuntimeException('مسیر فایل منبع تردد تنظیم نشده است.');
    }

    // ردیف‌هایی که «وضعیت حذف» آن‌ها تأیید نشده است (شامل ردیف‌های قبل از Migration 1.33).
    $where = $hasSourceDelete
        ? 'WHERE (source_deleted = 0 OR source_deleted IS NULL) AND punch_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)'
        : 'WHERE punch_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)';
    $stmt = db()->prepare('SELECT id, punch_date, punch_time, source_uid, source_card, source_payload
        FROM food_ticket_events ' . $where . ' ORDER BY id DESC LIMIT ' . $limit);
    $stmt->execute([$days - 1]);
    $events = $stmt->fetchAll();
    $out['checked'] = count($events);
    if (!$events) {
        $out['mode'] = 'بدون ردیف';
        return $out;
    }

    $attendancePassword = function_exists('food_ticket_attendance_password')
        ? food_ticket_attendance_password($config)
        : (function_exists('food_ticket_attendance_factory_password') ? food_ticket_attendance_factory_password() : '');
    $sourceDb = food_ticket_odbc($attendancePath, $attendancePassword);
    $out['mode'] = food_ticket_is_ps_access($sourceDb) ? 'PowerShell (OLEDB)' : 'ODBC';

    // آیتم قابل‌استفاده در مسیر حذف را از payload ذخیره‌شده می‌سازیم.
    $buildItem = static function (array $event): array {
        $row = [];
        $payload = (string) ($event['source_payload'] ?? '');
        if ($payload !== '') {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $row = $decoded;
            }
        }
        $dateRaw = $row['c_date'] ?? null;
        $timeRaw = $row['c_time'] ?? null;
        return [
            'row' => $row,
            'columns' => [], // کلیدهای نرمال‌شده با نام واقعی ستون Access یکسان‌اند (Jet به بزرگی/کوچکی حساس نیست)
            'uid' => trim((string) ($event['source_uid'] ?? '')),
            'card' => trim((string) ($event['source_card'] ?? '')),
            'date_raw' => $dateRaw !== null ? $dateRaw : (string) ($event['punch_date'] ?? ''),
            'time_raw' => $timeRaw !== null ? $timeRaw : (string) ($event['punch_time'] ?? ''),
        ];
    };

    $mark = static function (int $eventId, bool $deleted, string $note) use ($hasSourceDelete): void {
        if (!$hasSourceDelete || !function_exists('food_ticket_mark_source_delete')) {
            return;
        }
        try {
            food_ticket_mark_source_delete($eventId, $deleted, $note);
        } catch (Throwable $e) {
            error_log('[food-cleanup] mark failed id=' . $eventId . ': ' . $e->getMessage());
        }
    };

    try {
        // ── ۱) دستهٔ کلید اصلی: یک فرمان IN برای چند ردیف (سریع، دقیق) ──
        $pkGroups = [];   // column => [value => eventId]
        $rest = [];       // ردیف‌های بدون کلید اصلی
        foreach ($events as $event) {
            $item = $buildItem($event);
            $pk = food_ticket_source_primary_key($item);
            if ($pk !== null && !$dryRun) {
                $pkGroups[$pk['column']][(int) $pk['value']] = (int) $event['id'];
            } else {
                $rest[] = ['event' => $event, 'item' => $item, 'pk' => $pk];
            }
        }
        foreach ($pkGroups as $column => $values) {
            foreach (array_chunk($values, 40, true) as $chunk) {
                $ids = array_map('intval', array_keys($chunk));
                $whereIn = food_ticket_ident((string) $column) . ' IN (' . implode(',', $ids) . ')';
                try {
                    if (food_ticket_is_ps_access($sourceDb)) {
                        $result = food_ticket_access_delete_powershell_ex($sourceDb, 'DELETE FROM ' . food_ticket_ident(food_ticket_source_table()) . ' WHERE ' . $whereIn, [], true);
                        $okDel = !empty($result['ok']) && (int) $result['affected'] >= 1;
                        $matched = (int) $result['matched'];
                        $affected = (int) $result['affected'];
                        $note = $okDel ? 'primary_key_batch:' . $column : ('batch_failed: ' . (string) ($result['error'] ?? ''));
                    } else {
                        $countRes = @odbc_exec($sourceDb, 'SELECT COUNT(*) FROM ' . food_ticket_ident(food_ticket_source_table()) . ' WHERE ' . $whereIn);
                        $matched = 0;
                        if ($countRes !== false && @odbc_fetch_row($countRes)) {
                            $matched = (int) @odbc_result($countRes, 1);
                        }
                        $affected = 0;
                        if ($matched >= 1) {
                            $delRes = @odbc_exec($sourceDb, 'DELETE FROM ' . food_ticket_ident(food_ticket_source_table()) . ' WHERE ' . $whereIn);
                            if ($delRes !== false) {
                                $affected = (int) @odbc_num_rows($delRes);
                            }
                        }
                        $okDel = $affected >= 1;
                        $note = $okDel ? 'primary_key_batch:' . $column : 'batch_failed (matched=' . $matched . ')';
                    }
                    $out['matched'] += $matched;
                    foreach ($chunk as $value => $eventId) {
                        if ($okDel) {
                            $out['deleted']++;
                            $mark((int) $eventId, true, $note);
                        } else {
                            $out['failed']++;
                            $mark((int) $eventId, false, $note);
                        }
                    }
                } catch (Throwable $e) {
                    $out['failed'] += count($chunk);
                    error_log('[food-cleanup] batch delete failed: ' . $e->getMessage());
                }
                if ($dryRun) {
                    break;
                }
            }
        }

        // ── ۲) ردیف‌های بدون کلید اصلی: همان زنجیرهٔ شرط‌های دقیق ──
        foreach ($rest as $entry) {
            $event = $entry['event'];
            $item = $entry['item'];
            if ($dryRun) {
                $candidates = food_ticket_source_delete_candidates($item);
                $out['items'][] = [
                    'id' => (int) $event['id'],
                    'punch' => (string) $event['punch_date'] . ' ' . (string) $event['punch_time'],
                    'uid' => (string) $event['source_uid'],
                    'primary_key' => $entry['pk'],
                    'candidates' => array_map(static fn (array $c): array => ['label' => $c[0], 'where' => $c[1]], $candidates),
                ];
                continue;
            }
            $del = [];
            $okDel = food_ticket_delete_source($sourceDb, $item, $config, $del);
            if ($okDel) {
                $out['deleted']++;
            } elseif (!empty($del['skipped'])) {
                $out['skipped']++;
            } else {
                $out['failed']++;
                $out['items'][] = [
                    'id' => (int) $event['id'],
                    'uid' => (string) $event['source_uid'],
                    'reason' => (string) ($del['reason'] ?? ''),
                    'tried' => $del['tried'] ?? [],
                ];
            }
            $out['matched'] += (int) ($del['matched'] ?? 0);
            $mark((int) $event['id'], (bool) $okDel, (string) ($del['strategy'] ?? ($del['reason'] ?? '')));
        }
    } finally {
        if (function_exists('food_ticket_access_close')) {
            food_ticket_access_close($sourceDb);
        }
    }

    if (!$dryRun && function_exists('activity_log')) {
        try {
            activity_log([
                'action_code' => 'food_ticket_cleanup_source_rows',
                'module' => 'food',
                'target_type' => 'food_ticket_event',
                'target_id' => 0,
                'target_label' => 'پاک‌سازی ردیف‌های باقی‌مانده SOURCE_TABLE',
                'meta' => ['checked' => $out['checked'], 'deleted' => $out['deleted'], 'failed' => $out['failed'], 'days' => $days],
            ]);
        } catch (Throwable) {
        }
    }
    $out['ok'] = $out['failed'] === 0;
    return $out;
}

function food_ticket_retry_failed(int $limit = 50): array
{
    $config = food_ticket_config(true);
    if (!(int) ($config['enabled'] ?? 0)) {
        return ['ok' => 0, 'queued' => 0, 'failed' => 0, 'disabled' => true];
    }
    $lock = @fopen(APP_ROOT . '/storage/food-ticket.lock', 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return ['ok' => 0, 'failed' => 1];
    }
    $pendingOnly = (function_exists('food_ticket_delivery_supported') && food_ticket_delivery_supported())
        ? ' AND (delivery_status IS NULL OR delivery_status = "pending")' : '';
    $query = db()->prepare('SELECT id FROM food_ticket_events WHERE print_status IN ("print_error", "failed")' . $pendingOnly . ' ORDER BY updated_at ASC LIMIT ' . max(1, min(200, $limit)));
    $query->execute();
    $queued = 0;
    $failed = 0;
    try {
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try {
                try {
                    db()->prepare('UPDATE food_ticket_events SET retry_count = 0, print_attempts = 0, next_retry_at = NULL WHERE id = ? AND print_status IN ("print_error", "failed")')->execute([(int) $id]);
                } catch (Throwable) {
                    db()->prepare('UPDATE food_ticket_events SET print_attempts = 0 WHERE id = ? AND print_status IN ("print_error", "failed")')->execute([(int) $id]);
                }
                food_ticket_enqueue_print((int) $id, $config);
                $queued++;
            } catch (Throwable) {
                $failed++;
            }
        }
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
    $q = function_exists('food_ticket_process_print_queue') ? food_ticket_process_print_queue_locked($config) : ['printed' => 0];
    if (function_exists('activity_log')) {
        try {
            activity_log([
                'action_code' => 'food_ticket_retry',
                'module' => 'food',
                'target_type' => 'food_ticket_event',
                'target_id' => 0,
                'target_label' => 'چاپ مجدد خطاها',
                'meta' => ['queued' => $queued, 'failed' => $failed, 'printed' => (int) ($q['printed'] ?? 0)],
            ]);
        } catch (Throwable) {
        }
    }
    return ['ok' => (int) ($q['printed'] ?? 0), 'queued' => $queued, 'failed' => $failed + (int) ($q['failed'] ?? 0), 'queue' => $q];
}

function food_ticket_worker_heartbeat(array $result = [], ?Throwable $error = null): void
{
    try {
        if (!db_table_exists('food_ticket_worker_status')) {
            return;
        }
        $status = $error ? 'error' : ((int) ($result['errors'] ?? 0) > 0 ? 'warning' : 'ok');
        $message = $error ? $error->getMessage() : (string) ($result['message'] ?? 'چرخه پردازش انجام شد.');
        db()->prepare('INSERT INTO food_ticket_worker_status (id, host_name, process_id, last_seen, status, last_message, last_error, processed_count, printed_count, error_count) VALUES (1, ?, ?, NOW(), ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE host_name = VALUES(host_name), process_id = VALUES(process_id), last_seen = NOW(), status = VALUES(status), last_message = VALUES(last_message), last_error = VALUES(last_error), processed_count = VALUES(processed_count), printed_count = VALUES(printed_count), error_count = VALUES(error_count)')->execute([
            gethostname() ?: 'unknown', (int) (getmypid() ?: 0), $status, $message,
            $error?->getMessage(), (int) ($result['processed'] ?? 0), (int) ($result['printed'] ?? 0), (int) ($result['errors'] ?? ($error ? 1 : 0)),
        ]);
    } catch (Throwable $heartbeatError) {
        error_log('Food ticket heartbeat failed: ' . $heartbeatError->getMessage());
    }
}

function food_ticket_test_connections(array $config): string
{
    $sourceDb = null;
    $messages = [];
    try {
        if (trim((string) ($config['attendance_path'] ?? '')) === '') {
            throw new RuntimeException('تنظیم اتصال تردد کامل نیست.');
        }
        $sourceDb = food_ticket_odbc((string) $config['attendance_path'], food_ticket_attendance_password($config));
        food_ticket_access_rows($sourceDb, food_ticket_source_table(), 1);
        $messages[] = 'اتصال تردد موفق بود.';
    } catch (Throwable $e) {
        $messages[] = 'اتصال تردد ناموفق بود.';
    } finally {
        food_ticket_access_close($sourceDb);
    }
    try {
        food_order_schema_ensure();
        $messages[] = 'بررسی سفارش‌ها موفق بود.';
    } catch (Throwable $e) {
        $messages[] = 'بررسی سفارش‌ها ناموفق بود.';
    }
    try {
        food_ticket_send_to_printer([
            'full_name' => 'تست چاپ',
            'national_code' => '0000000000',
            'personnel_code' => 'TEST',
            'food_type' => 'فیش آزمایشی',
            'punch_date' => date('Y-m-d'),
            'punch_time' => date('H:i:s'),
        ], $config);
        $messages[] = 'فیش آزمایشی به چاپگر ارسال شد.';
    } catch (Throwable $e) {
        $messages[] = 'چاپ آزمایشی: ' . $e->getMessage();
    }
    return implode(' | ', $messages);
}

function food_ticket_save_config(array $data, array $user): void
{
    require_food_ticket_access();
    if (!food_ticket_installed()) {
        throw new RuntimeException('ابتدا upgrade-1.9-food-ticket.sql را روی دیتابیس اجرا کنید.');
    }
    $current = food_ticket_config();
    // اتصال سفارش Access قطع است؛ مقادیر قبلی صرفاً برای rollback نگه داشته می‌شوند و از ورودی قابل تغییر نیستند.
    $password = trim((string) ($data['attendance_password'] ?? ''));
    if ($password !== '') {
        $passwordEnc = food_ticket_encrypt($password);
        if ($passwordEnc === '' || food_ticket_decrypt($passwordEnc) !== $password) {
            throw new RuntimeException('رمزنگاری رمز Access ناموفق بود. مقدار app.key در config.php را بررسی کنید (نباید خالی باشد).');
        }
    } else {
        $passwordEnc = (string) ($current['attendance_password_enc'] ?? '');
    }
    food_ticket_ensure_printer_mode_column();
    $wantedMode = food_ticket_normalize_printer_mode($data['printer_mode'] ?? $data['mode'] ?? 'tcp_raw');
    if (!in_array($wantedMode, ['tcp_raw', 'windows_share'], true)) {
        $wantedMode = 'tcp_raw';
    }
    [$wantedHost, $wantedPort] = food_ticket_normalize_printer_host((string) ($data['printer_host'] ?? ''), (int) ($data['printer_port'] ?? 9100));
    $data['printer_host'] = $wantedHost;
    $data['printer_port'] = $wantedPort;
    if ($wantedMode === 'tcp_raw' && $wantedHost === '') {
        throw new RuntimeException('برای چاپ شبکه‌ای باید IP یا نام چاپگر را وارد کنید.');
    }
    // نام واقعی ستون‌های مسیر/رمز/حذف ردیف: نام تازه در دیتابیس‌های جدید، نام قدیمی در نصب‌های قبلی
    $colPath = food_ticket_db_column('attendance_path');
    $colPwd = food_ticket_db_column('attendance_password_enc');
    $colCut = food_ticket_db_column('cut_source_rows');
    $query = db()->prepare('INSERT INTO food_ticket_config (id, enabled, ' . $colPath . ', ' . $colPwd . ', printer_mode, printer_host, printer_port, printer_share, poll_seconds, max_batch, guest_card_uids, max_guest_daily, ' . $colCut . ', updated_by) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), ' . $colPath . ' = VALUES(' . $colPath . '), ' . $colPwd . ' = VALUES(' . $colPwd . '), printer_mode = VALUES(printer_mode), printer_host = VALUES(printer_host), printer_port = VALUES(printer_port), printer_share = VALUES(printer_share), poll_seconds = VALUES(poll_seconds), max_batch = VALUES(max_batch), guest_card_uids = VALUES(guest_card_uids), max_guest_daily = VALUES(max_guest_daily), ' . $colCut . ' = VALUES(' . $colCut . '), updated_by = VALUES(updated_by)');
    $query->execute([
        !empty($data['enabled']) ? 1 : 0, trim((string) ($data['attendance_path'] ?? '')), $passwordEnc,
        $wantedMode, $wantedHost,
        $wantedPort, trim((string) ($data['printer_share'] ?? '')),
        max(1, min(60, (int) ($data['poll_seconds'] ?? 2))), max(1, min(5000, (int) ($data['max_batch'] ?? 100))),
        trim((string) ($data['guest_card_uids'] ?? '')), max(0, min(10000, (int) ($data['max_guest_daily'] ?? 20))),
        !empty($data['cut_source_rows']) ? 1 : 0, (int) $user['id'],
    ]);
    // خواندن مجدد: اگر پایگاه‌داده مقدار را عوض کرده یا نپذیرفته باشد، همین‌جا خطای روشن بدهیم.
    $saved = db()->query('SELECT printer_mode, printer_host, printer_port FROM food_ticket_config WHERE id = 1')->fetch() ?: [];
    $savedMode = food_ticket_normalize_printer_mode($saved['printer_mode'] ?? '');
    if ($savedMode !== $wantedMode) {
        throw new RuntimeException('حالت چاپ در پایگاه‌داده ذخیره نشد (انتظار: ' . $wantedMode . '، ذخیره‌شده: ' . ($saved['printer_mode'] ?? '—') . '). ستون printer_mode جدول food_ticket_config را بررسی کنید.');
    }
    if (trim((string) ($saved['printer_host'] ?? '')) !== $wantedHost) {
        throw new RuntimeException('آدرس IP چاپگر در پایگاه‌داده ذخیره نشد؛ مقدار واردشده با مقدار خوانده‌شده برابر نیست.');
    }
    if ((int) ($saved['printer_port'] ?? 0) !== $wantedPort) {
        throw new RuntimeException('پورت چاپگر در پایگاه‌داده ذخیره نشد؛ مقدار واردشده با مقدار خوانده‌شده برابر نیست.');
    }
    food_ticket_config(true);
    save_audit((int) $user['id'], 'food_ticket_config_saved', null, ['printer_mode' => $wantedMode, 'printer_host' => $wantedHost]);
}

function food_ticket_handle_post(string $action, array $user): void
{
    if (!in_array($action, ['food_ticket_save_config', 'food_ticket_test', 'food_ticket_process', 'food_ticket_retry'], true)) {
        return;
    }
    require_food_ticket_access();
    if ($action === 'food_ticket_save_config') {
        food_ticket_save_config($_POST, $user);
        flash('success', 'تنظیمات چاپ فیش ذخیره شد.');
    } elseif ($action === 'food_ticket_test') {
        flash('success', food_ticket_test_connections(food_ticket_config()));
    } elseif ($action === 'food_ticket_process') {
        $result = food_ticket_process_batch();
        flash($result['errors'] > 0 ? 'warning' : 'success', $result['message']);
    } else {
        $result = food_ticket_retry_failed();
        if (!empty($result['disabled'])) {
            flash('warning', 'چاپ فیش در تنظیمات غیرفعال است.');
        } else {
            flash($result['failed'] > 0 ? 'warning' : 'success', 'چاپ مجدد موفق: ' . $result['ok'] . ' | ناموفق: ' . $result['failed']);
        }
    }
    redirect('index.php?page=food-ticket');
}

function food_ticket_label(string $value): string
{
    return [
        'printed' => 'چاپ‌شده', 'print_error' => 'خطای چاپ', 'no_food' => 'غذا ندارد',
        'unknown' => 'کاربر ناشناس', 'inactive' => 'کاربر غیرفعال', 'repeat' => 'تردد مجدد',
        'guest' => 'فیش مهمان', 'guest_limit' => 'سقف مهمان', 'config_error' => 'خطای تنظیمات',
        'unknown_uid' => 'L_UID ناشناخته', 'group' => 'فیش گروهی',
    ][$value] ?? $value;
}

/**
 * تبدیل رشتهٔ Windows-1256 (کدپیج ANSI فارسی/عربی ویندوز) به UTF-8.
 * بدون وابستگی به mbstring (که نام CP1256 را نمی‌شناسد)؛ ابتدا iconv، در غیر این صورت جدول داخلی.
 */
function food_ticket_cp1256_to_utf8(string $s): string
{
    if (function_exists('iconv')) {
        try {
            $out = @iconv('CP1256', 'UTF-8//IGNORE', $s);
            if (is_string($out) && $out !== '') {
                return $out;
            }
        } catch (Throwable $e) {
            // ادامه با جدول داخلی
        }
    }
    static $map = null;
    if ($map === null) {
        $map = [
        "\x80" => "\u{20AC}",
        "\x81" => "\u{67E}",
        "\x82" => "\u{201A}",
        "\x83" => "\u{192}",
        "\x84" => "\u{201E}",
        "\x85" => "\u{2026}",
        "\x86" => "\u{2020}",
        "\x87" => "\u{2021}",
        "\x88" => "\u{2C6}",
        "\x89" => "\u{2030}",
        "\x8A" => "\u{679}",
        "\x8B" => "\u{2039}",
        "\x8C" => "\u{152}",
        "\x8D" => "\u{686}",
        "\x8E" => "\u{698}",
        "\x8F" => "\u{688}",
        "\x90" => "\u{6AF}",
        "\x91" => "\u{2018}",
        "\x92" => "\u{2019}",
        "\x93" => "\u{201C}",
        "\x94" => "\u{201D}",
        "\x95" => "\u{2022}",
        "\x96" => "\u{2013}",
        "\x97" => "\u{2014}",
        "\x98" => "\u{6A9}",
        "\x99" => "\u{2122}",
        "\x9A" => "\u{691}",
        "\x9B" => "\u{203A}",
        "\x9C" => "\u{153}",
        "\x9D" => "\u{200C}",
        "\x9E" => "\u{200D}",
        "\x9F" => "\u{6BA}",
        "\xA0" => "\u{A0}",
        "\xA1" => "\u{60C}",
        "\xA2" => "\u{A2}",
        "\xA3" => "\u{A3}",
        "\xA4" => "\u{A4}",
        "\xA5" => "\u{A5}",
        "\xA6" => "\u{A6}",
        "\xA7" => "\u{A7}",
        "\xA8" => "\u{A8}",
        "\xA9" => "\u{A9}",
        "\xAA" => "\u{6BE}",
        "\xAB" => "\u{AB}",
        "\xAC" => "\u{AC}",
        "\xAD" => "\u{AD}",
        "\xAE" => "\u{AE}",
        "\xAF" => "\u{AF}",
        "\xB0" => "\u{B0}",
        "\xB1" => "\u{B1}",
        "\xB2" => "\u{B2}",
        "\xB3" => "\u{B3}",
        "\xB4" => "\u{B4}",
        "\xB5" => "\u{B5}",
        "\xB6" => "\u{B6}",
        "\xB7" => "\u{B7}",
        "\xB8" => "\u{B8}",
        "\xB9" => "\u{B9}",
        "\xBA" => "\u{61B}",
        "\xBB" => "\u{BB}",
        "\xBC" => "\u{BC}",
        "\xBD" => "\u{BD}",
        "\xBE" => "\u{BE}",
        "\xBF" => "\u{61F}",
        "\xC0" => "\u{6C1}",
        "\xC1" => "\u{621}",
        "\xC2" => "\u{622}",
        "\xC3" => "\u{623}",
        "\xC4" => "\u{624}",
        "\xC5" => "\u{625}",
        "\xC6" => "\u{626}",
        "\xC7" => "\u{627}",
        "\xC8" => "\u{628}",
        "\xC9" => "\u{629}",
        "\xCA" => "\u{62A}",
        "\xCB" => "\u{62B}",
        "\xCC" => "\u{62C}",
        "\xCD" => "\u{62D}",
        "\xCE" => "\u{62E}",
        "\xCF" => "\u{62F}",
        "\xD0" => "\u{630}",
        "\xD1" => "\u{631}",
        "\xD2" => "\u{632}",
        "\xD3" => "\u{633}",
        "\xD4" => "\u{634}",
        "\xD5" => "\u{635}",
        "\xD6" => "\u{636}",
        "\xD7" => "\u{D7}",
        "\xD8" => "\u{637}",
        "\xD9" => "\u{638}",
        "\xDA" => "\u{639}",
        "\xDB" => "\u{63A}",
        "\xDC" => "\u{640}",
        "\xDD" => "\u{641}",
        "\xDE" => "\u{642}",
        "\xDF" => "\u{643}",
        "\xE0" => "\u{E0}",
        "\xE1" => "\u{644}",
        "\xE2" => "\u{E2}",
        "\xE3" => "\u{645}",
        "\xE4" => "\u{646}",
        "\xE5" => "\u{647}",
        "\xE6" => "\u{648}",
        "\xE7" => "\u{E7}",
        "\xE8" => "\u{E8}",
        "\xE9" => "\u{E9}",
        "\xEA" => "\u{EA}",
        "\xEB" => "\u{EB}",
        "\xEC" => "\u{649}",
        "\xED" => "\u{64A}",
        "\xEE" => "\u{EE}",
        "\xEF" => "\u{EF}",
        "\xF0" => "\u{64B}",
        "\xF1" => "\u{64C}",
        "\xF2" => "\u{64D}",
        "\xF3" => "\u{64E}",
        "\xF4" => "\u{F4}",
        "\xF5" => "\u{64F}",
        "\xF6" => "\u{650}",
        "\xF7" => "\u{F7}",
        "\xF8" => "\u{651}",
        "\xF9" => "\u{F9}",
        "\xFA" => "\u{652}",
        "\xFB" => "\u{FB}",
        "\xFC" => "\u{FC}",
        "\xFD" => "\u{200E}",
        "\xFE" => "\u{200F}",
        "\xFF" => "\u{6D2}"
        ];
    }
    return strtr($s, $map);
}

/**
 * تبدیل هر مقدار به رشته UTF-8 امن برای JSON
 */
function food_ticket_utf8(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    $s = (string) $value;
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;

    $hasArabic = (bool) preg_match('/\p{Arabic}/u', $s);
    $hasHigh = (bool) preg_match('/[\x80-\xFF]/', $s);
    $hasQuestionMojibake = (bool) preg_match('/\?{2,}/', $s) || (bool) preg_match('/[\x80-\xFF].*\?|\?.*[\x80-\xFF]/s', $s);
    $isUtf8 = function_exists('mb_check_encoding') ? mb_check_encoding($s, 'UTF-8') : (bool) preg_match('//u', $s);

    // اگر عربی یونیکد ندارد ولی بایت‌های غیر ASCII دارد → احتمالاً CP1256/CP1252 است
    if ((!$hasArabic && $hasHigh) || !$isUtf8 || $hasQuestionMojibake) {
        $converted = food_ticket_cp1256_to_utf8($s);
        if ($converted !== '' && (preg_match('/\p{Arabic}/u', $converted) || !$hasArabic)) {
            $s = $converted;
        }
        // تلاش دوم: Windows-1252 → UTF-8
        if (!preg_match('/\p{Arabic}/u', $s) && $hasHigh && function_exists('mb_convert_encoding')) {
            $try = @mb_convert_encoding($s, 'UTF-8', 'Windows-1256,Windows-1252,ISO-8859-1');
            if (is_string($try) && preg_match('/\p{Arabic}/u', $try)) {
                $s = $try;
            }
        }
        do {
            $s = preg_replace('/(?<=\p{Arabic})\?|\?(?=\p{Arabic})/u', "\u{06CC}", $s, -1, $fixedYeh) ?? $s;
        } while (!empty($fixedYeh));
        if (function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) {
            $s = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        }
    }
    return $s;
}

function food_ticket_api_json(array $payload, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    if ($json === false) {
        $json = json_encode([
            'error' => 'خطا در ساخت JSON: ' . json_last_error_msg(),
            'items' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    echo $json !== false ? $json : '{"error":"json_encode failed","items":[]}';
    exit;
}

function food_ticket_api_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $body = json_decode($raw, true);
    return is_array($body) ? $body : [];
}

/**
 * وضعیت «همان چیزی که واقعاً در پایگاه‌داده نشسته» برای چاپگر.
 * پنل با این خروجی می‌تواند بگوید کدام مقدار ذخیره شده و آیا با انتخاب کاربر یکی است.
 */
function food_ticket_api_printer_state(): array
{
    $config = food_ticket_config(true);
    $mode = food_ticket_normalize_printer_mode($config['printer_mode'] ?? 'tcp_raw');
    $host = trim((string) ($config['printer_host'] ?? ''));
    $port = max(1, (int) ($config['printer_port'] ?? 9100));
    $share = trim((string) ($config['printer_share'] ?? ''));
    $npTarget = function_exists('food_ticket_np_target') ? food_ticket_np_target() : ['host' => '', 'port' => 9100, 'source' => 'none'];
    return [
        'printer' => [
            'ok' => true,
            'mode' => $mode === 'tcp_raw' ? 'socket' : 'windows-spooler',
            'mode_db' => $mode,
            'host' => $host,
            'port' => $port,
            'name' => $share,
            'share' => $share,
            'ready' => $mode === 'tcp_raw' ? $host !== '' : $share !== '',
            'target' => $host !== '' ? ($host . ':' . $port) : ($share !== '' ? $share : ''),
            'calibration_host' => (string) ($npTarget['host'] ?? ''),
            'calibration_source' => (string) ($npTarget['source'] ?? 'none'),
        ],
    ];
}

/**
 * عیب‌یابی چاپگر: چه چیزی ذخیره شده، کارت کالیبراسیون کجا می‌رود و آیا چاپگر پاسخ می‌دهد.
 */
function food_ticket_api_print_diag(): array
{
    $state = food_ticket_api_printer_state();
    $printer = $state['printer'];
    $reachable = null;
    $reachableError = '';
    if ($printer['mode_db'] === 'tcp_raw' && $printer['host'] !== '') {
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen((string) $printer['host'], (int) $printer['port'], $errno, $errstr, 3);
        if ($sock) {
            fclose($sock);
            $reachable = true;
        } else {
            $reachable = false;
            $reachableError = $errstr !== '' ? $errstr : ('خطا ' . $errno);
        }
    }
    $fileError = '';
    $fileConfig = [];
    if (function_exists('food_ticket_np_json_file')) {
        try {
            $path = food_ticket_np_json_file();
            $fileConfig = ['path' => $path, 'exists' => is_file($path), 'writable' => is_file($path) ? is_writable($path) : is_writable(dirname($path))];
            if (is_file($path)) {
                $fileConfig['content'] = json_decode((string) @file_get_contents($path), true) ?: [];
            }
        } catch (Throwable $e) {
            $fileError = $e->getMessage();
        }
    }
    $align = function_exists('food_ticket_np_align_state') ? food_ticket_np_align_state() : [];
    return [
        'ok' => true,
        'panel_version_expected' => FOOD_TICKET_PANEL_VERSION,
        'printer' => $printer,
        'reachable' => $reachable,
        'reachable_error' => $reachableError,
        'align' => $align,
        'netprint_file' => $fileConfig,
        'netprint_file_error' => $fileError,
        'verdict' => [],
    ];
}

function food_ticket_api_config(): array
{
    $config = food_ticket_config();
    $brand = food_ticket_brand_params();
    $template = function_exists('food_ticket_template') ? food_ticket_template() : [];
    // هویت دیتابیس برای مرورگر: اگر دیتابیس خام/تازه نصب شده باشد، پنل باید کش قدیمی
    // localStorage خود را دور بریزد تا داده‌ای که در دیتابیس نیست نمایش داده نشود.
    $dbState = function_exists('db_install_state')
        ? db_install_state()
        : ['token' => '', 'fresh' => true, 'existing' => false, 'counts' => []];
    return [
        'db' => $dbState,
        'attendancePath' => (string) ($config['attendance_path'] ?? ''),
        'attendanceTable' => food_ticket_source_table(),
        // مقادیر legacy برای سازگاری API؛ در رابط کاربری نمایش داده نمی‌شوند.
        'pollSeconds' => (int) ($config['poll_seconds'] ?? 2),
        'guestCardUIDs' => implode(',', food_ticket_guest_cards($config)),
        'maxGuestTicketsPerDay' => (int) ($config['max_guest_daily'] ?? 20),
        'guestCapMode' => food_guest_cap_mode(),
        'guestCapStart' => substr(food_guest_cap_start(), 0, 5),
        'guestCapCutoff' => substr(food_guest_cap_cutoff(), 0, 5),
        'guestCapEnd' => substr(food_guest_cap_end(), 0, 5),
        'guestFoodType' => 'مهمان',
        'cutSourceRows' => !empty($config['cut_source_rows']),
        'enabled' => !empty($config['enabled']),
        'brandName' => (string) ($brand['brand_name'] ?? 'سامانه چاپ فیش غذا'),
        'brandLogo' => (string) ($brand['brand_logo'] ?? ''),
        'panelVersion' => FOOD_TICKET_PANEL_VERSION,
        'printer' => [
            'mode' => (food_ticket_normalize_printer_mode($config['printer_mode'] ?? 'tcp_raw') === 'tcp_raw' ? 'socket' : 'windows-spooler'),
            'mode_db' => food_ticket_normalize_printer_mode($config['printer_mode'] ?? 'tcp_raw'),
            'host' => (string) ($config['printer_host'] ?? ''),
            'port' => (int) ($config['printer_port'] ?? 9100),
            'name' => (string) ($config['printer_share'] ?? ''),
            'share' => (string) ($config['printer_share'] ?? ''),
            'ready' => (trim((string)($config['printer_host'] ?? '')) !== '' || trim((string)($config['printer_share'] ?? '')) !== ''),
            'encoding' => 'CP864',
            'company' => (string) ($template['company'] ?? ''),
            'title' => (string) ($template['title'] ?? ''),
            'footer' => (string) ($template['footer'] ?? ''),
            'showLogo' => !empty($template['show_logo']),
            'paper' => '80mm',
        ],
    ];
}


/**
 * گزارش غایبین: سفارش داده‌اند ولی فیش چاپ‌شده ندارند.
 * @return list<array{nat:string,pc:string,first:string,last:string,food:string,foodDate:string,status:string}>
 */
/**
 * گزارش سفارش مهمان: فقط درخواست‌های فعال بازه؛ deputy = 'all' یا شناسهٔ معاونت.
 * فهرست معاونت‌ها هم برای ساخت لیست کشویی برگردانده می‌شود.
 */
function food_ticket_api_guest_requests_report(string $from, string $to, string $deputy): array
{
    $deputies = [];
    if (function_exists('org_deputy_units')) {
        $deputies = array_map(
            static fn (array $u): array => ['id' => (int) $u['id'], 'name' => (string) $u['name']],
            org_deputy_units()
        );
    }
    $sql = "SELECT r.id, r.request_date, r.organization, r.guest_count, r.requester_name, r.deputy_unit_id,
                   ou.name AS deputy_name, c.food_name
              FROM food_guest_requests r
              LEFT JOIN org_units ou ON ou.id = r.deputy_unit_id
              LEFT JOIN food_catalog c ON c.id = r.food_id
             WHERE r.status = 'active' AND r.request_date BETWEEN ? AND ?";
    $params = [$from, $to];
    if ($deputy !== 'all') {
        $sql .= ' AND r.deputy_unit_id = ?';
        $params[] = (int) $deputy;
    }
    $sql .= ' ORDER BY ou.name, r.request_date, r.id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'id' => (int) $r['id'],
            'date' => (string) $r['request_date'],
            'organization' => (string) $r['organization'],
            'guest_count' => (int) $r['guest_count'],
            'food_name' => (string) ($r['food_name'] ?? ''),
            'requester_name' => (string) $r['requester_name'],
            'deputy_unit_id' => $r['deputy_unit_id'] === null ? null : (int) $r['deputy_unit_id'],
            'deputy_name' => (string) ($r['deputy_name'] ?? ''),
        ];
    }
    return ['items' => $items, 'deputies' => $deputies, 'from' => $from, 'to' => $to, 'deputy_id' => $deputy];
}

function food_ticket_api_absent(string $fromDate, ?string $toDate = null): array
{
    $fromDate = food_ticket_parse_date($fromDate) ?: trim($fromDate);
    $toDate = food_ticket_parse_date($toDate ?? $fromDate) ?: trim((string) ($toDate ?? $fromDate));
    if ($fromDate === '' || $toDate === '' || $fromDate > $toDate) {
        return [];
    }
    if (!function_exists('food_order_internal_items') || !function_exists('food_order_mode') || food_order_mode() !== 'INTERNAL-DB') {
        throw new RuntimeException('گزارش غایبین در دسترس نیست.');
    }

    // سفارش‌های بازه فقط از منبع داخلی خوانده می‌شوند؛ خطای MySQL نباید به «بدون سفارش» تبدیل شود.
    $orders = [];
    try {
        $items = food_ticket_api_orders($fromDate, '', $toDate);
        foreach ($items as $it) {
            $nat = food_ticket_digits((string) ($it['nat'] ?? ''));
            if ($nat === '') {
                continue;
            }
            $day = (string) ($it['foodDate'] ?? $fromDate);
            $orders[$day][$nat] = [
                'nat' => $nat,
                'pc' => (string) ($it['pc'] ?? ''),
                'first' => (string) ($it['first'] ?? ''),
                'last' => (string) ($it['last'] ?? ''),
                'food' => (string) ($it['food'] ?? ''),
                'foodDate' => $day,
            ];
        }
    } catch (Throwable $e) {
        error_log('[absent] internal orders query failed: ' . $e->getMessage());
        throw $e;
    }

    // کسانی که فیش چاپ‌شده دارند از لیست غایب خارج می‌شوند
    $printed = [];
    try {
        $q = db()->prepare(
            "SELECT punch_date, national_code, personnel_code FROM food_ticket_events
             WHERE punch_date BETWEEN ? AND ?
               AND print_status = 'printed'
              AND event_type IN ('printed','print','guest')"
        );
        $q->execute([$fromDate, $toDate]);
        foreach ($q->fetchAll() as $row) {
            $day = (string) ($row['punch_date'] ?? '');
            $nat = food_ticket_digits((string) ($row['national_code'] ?? ''));
            if ($nat !== '') {
                $printed[$day]['nat:' . $nat] = true;
            }
            $pc = trim((string) ($row['personnel_code'] ?? ''));
            if ($pc !== '') {
                $printed[$day]['pc:' . $pc] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('[absent] events: ' . $e->getMessage());
    }

    // «غایب اعلام‌شده»: کسانی که برای همان تاریخ در فرم گروه، غایب ثبت شده‌اند
    // (آمار نگهبانی گروه‌های بیرون از ساختمان) — این‌ها با برچسب جداگانه نمایش داده می‌شوند.
    $declaredByDay = [];
    if (function_exists('food_ticket_absence_ensure') && food_ticket_absence_ensure()) {
        try {
            $aq = db()->prepare('SELECT absence_date, user_id FROM food_ticket_daily_absence WHERE absence_date BETWEEN ? AND ?');
            $aq->execute([$fromDate, $toDate]);
            foreach ($aq->fetchAll() as $arow) {
                $declaredByDay[(string) $arow['absence_date']][(int) $arow['user_id']] = true;
            }
        } catch (Throwable $e) {
            error_log('[absent] declared absence: ' . $e->getMessage());
        }
    }
    $userIdsByNational = [];
    $absent = [];
    foreach ($orders as $day => $dayOrders) {
        foreach ($dayOrders as $nat => $row) {
            $pc = trim((string) ($row['pc'] ?? ''));
            if (!empty($printed[$day]['nat:' . $nat]) || ($pc !== '' && !empty($printed[$day]['pc:' . $pc]))) {
                continue;
            }
            $row['full_name'] = trim(($row['first'] ?? '') . ' ' . ($row['last'] ?? ''));
            $declared = false;
            try {
                if ($nat !== '') {
                    if (!isset($userIdsByNational[$nat])) {
                        $uq = db()->prepare('SELECT id FROM users WHERE national_code = ? OR national_code = ? LIMIT 1');
                        $uq->execute([$nat, ltrim($nat, '0') ?: '0']);
                        $userIdsByNational[$nat] = (int) ($uq->fetchColumn() ?: 0);
                    }
                    $uid = (int) $userIdsByNational[$nat];
                    $declared = $uid > 0 && !empty($declaredByDay[(string) $day][$uid]);
                }
            } catch (Throwable $e) {
                error_log('[absent] user lookup: ' . $e->getMessage());
            }
            $row['declared_absent'] = $declared;
            $row['status'] = $declared
                ? 'غایب اعلام‌شده — فیش چاپ نشده'
                : 'غایب — سفارش دارد، فیش چاپ نشده';
            $absent[] = $row;
        }
    }
    return $absent;
}

/**
 * پایش رویدادها.
 * پیش‌فرض (بدون بازه) = فقط «امروزِ کاری سامانه» (به وقت تهران)؛ اگر امروز رویدادی نباشد،
 * خروجی خالی است — نه ۲۰۰ رکورد آخرِ همهٔ تاریخ‌ها. بازهٔ گذشته را باید گزارش‌ها بخواهند
 * (from/to). داده‌های قبلی حذف نمی‌شوند.
 */
function food_ticket_api_monitoring(?string $fromDate = null, ?string $toDate = null): array
{
    $hasDelivery = function_exists('food_ticket_delivery_supported') && food_ticket_delivery_supported();
    $deliveryCols = $hasDelivery ? ', delivery_status, delivered_at' : '';
    // ⚠️ وضعیت واقعی حذف منبع: پیش از این مقدار همیشه true گزارش می‌شد و پنل
    // «حذف‌شده از SOURCE_TABLE» را برای همهٔ ردیف‌ها نشان می‌داد، در حالی که ردیف‌ها
    // هنوز در فایل Access بودند. اکنون از ستون واقعی خوانده می‌شود.
    $hasSourceDelete = function_exists('food_ticket_source_delete_supported') && food_ticket_source_delete_supported();
    $sourceDeleteCols = $hasSourceDelete ? ', source_deleted, source_delete_note' : '';
    if ($fromDate === null || $toDate === null) {
        try {
            $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        } catch (Throwable) {
            $today = date('Y-m-d');
        }
        $fromDate = $toDate = $today;
    }
    $query = db()->prepare(
        'SELECT id, punch_date AS attendance_date, punch_time AS attendance_time, personnel_code AS pc_code, full_name, food_type, '
        . 'event_type AS result, print_status' . $deliveryCols . $sourceDeleteCols . ', ticket_key, last_error, '
        . 'CASE WHEN source_payload LIKE \'%"group_id"%\' THEN 1 ELSE 0 END AS from_group '
        . 'FROM food_ticket_events WHERE punch_date BETWEEN ? AND ? ORDER BY id DESC'
    );
    $query->execute([$fromDate, $toDate]);
    return array_map(static function (array $row) use ($hasDelivery, $hasSourceDelete): array {
        $rawResult = (string) ($row['result'] ?? '');
        $rawStatus = (string) ($row['print_status'] ?? 'not_printed');
        $resultLabel = food_ticket_label($rawResult);
        // event_type در لحظهٔ ثبت «printed» است و بعد از شکست چاپ عوض نمی‌شود؛ برچسب باید از وضعیت واقعی چاپ بیاید.
        if (in_array($rawResult, ['printed', 'guest', 'print'], true) && $rawStatus !== 'printed') {
            if (in_array($rawStatus, ['pending', 'printing'], true)) {
                $resultLabel = 'در صف چاپ';
            } elseif (in_array($rawStatus, ['print_error', 'failed'], true)) {
                $resultLabel = 'چاپ نشده';
            }
        }
        $delivery = $hasDelivery ? (string) ($row['delivery_status'] ?? 'pending') : 'pending';
        if ($rawStatus === 'held_absent') {
            // فیشی که به‌خاطر اعلام غیبتِ عضو از صف چاپ خارج شده است
            $resultLabel = 'متوقف — غایب اعلام‌شده';
        } elseif ($rawResult === 'unknown' && str_starts_with((string) ($row['ticket_key'] ?? ''), 'unknown:')) {
            // تردد با L_UID ناشناخته (نمایندهٔ بدون گروه) — متفاوت از «کاربر ناشناس» عادی
            $resultLabel = 'L_UID ناشناخته';
        } elseif ($delivery === 'delivered' && $rawStatus === 'printed') {
            $resultLabel = 'تحویل شد';
        }
        return [
            'id' => (int) ($row['id'] ?? 0),
            'attendance_date' => (string) ($row['attendance_date'] ?? ''),
            'attendance_time' => (string) ($row['attendance_time'] ?? ''),
            'pc_code' => (string) ($row['pc_code'] ?? ''),
            'full_name' => (string) ($row['full_name'] ?? ''),
            'food_type' => (string) ($row['food_type'] ?? ''),
            'result' => $resultLabel,
            'print_status' => $rawStatus,
            'print_state_label' => $rawStatus === 'held_absent' ? 'متوقف — غایب اعلام‌شده' : '',
            'delivery_status' => $delivery,
            'delivered_at' => (string) ($row['delivered_at'] ?? ''),
            'from_group' => (bool) (int) ($row['from_group'] ?? 0),
            'ticket_key' => (string) ($row['ticket_key'] ?? ''),
            'detail' => (string) ($row['last_error'] ?? ''),
            // null = ستون‌های ۱.۳۳ ساخته نشده‌اند (وضعیت نامشخص)، false = واقعاً از SOURCE_TABLE پاک نشده است.
            'source_deleted' => $hasSourceDelete ? (bool) ((int) ($row['source_deleted'] ?? 0) === 1) : null,
            'source_delete_note' => $hasSourceDelete ? (string) ($row['source_delete_note'] ?? '') : '',
        ];
    }, $query->fetchAll());
}


/** کلیدهای ۸رقمی (میلادی و شمسی) برای یک تاریخ میلادی Y-m-d */
function food_ticket_date_keys_for_iso(string $isoDate): array
{
    $keys = [];
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $isoDate, $m)) {
        $keys[] = $m[1] . $m[2] . $m[3];
        if (function_exists('gregorian_to_jalali')) {
            [$jy, $jm, $jd] = gregorian_to_jalali((int) $m[1], (int) $m[2], (int) $m[3]);
            $keys[] = sprintf('%04d%02d%02d', $jy, $jm, $jd);
        }
    }
    return $keys;
}


/**
 * کلید ۸رقمی تاریخ برای فیلتر سفارش (شمسی یا میلادی)
 * 1405/07/11 و 1405/7/11 و 2026-10-03
 */
function food_ticket_order_date_key(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $s = food_ticket_normalize_digits(trim((string) $value));
    // جداکننده‌های رایج
    $s = str_replace(['\\', '.', '-', ' ', '／', '٫'], '/', $s);
    $s = preg_replace('#/+#', '/', $s) ?? $s;
    if (preg_match('/(\d{4})\/(\d{1,2})\/(\d{1,2})/', $s, $m)) {
        return sprintf('%04d%02d%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }
    // yyyymmdd بدون جداکننده
    $d = preg_replace('/\D+/', '', $s) ?? '';
    if (strlen($d) >= 8) {
        return substr($d, 0, 8);
    }
    $parsed = food_ticket_parse_date($value);
    if ($parsed !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $parsed, $m)) {
        return $m[1] . $m[2] . $m[3];
    }
    return '';
}

function food_ticket_api_orders(string $foodDate, string $jalaliHint = '', ?string $toDate = null): array
{
    if (!function_exists('food_order_internal_items')) {
        throw new RuntimeException('ماژول سفارش‌ها در دسترس نیست.');
    }
    $from = food_order_iso_date(food_ticket_parse_date($foodDate) ?: $foodDate);
    $toInput = $toDate !== null ? (food_ticket_parse_date($toDate) ?: $toDate) : ($from ?? $foodDate);
    $to = food_order_iso_date($toInput);
    if ($from === null || $to === null) {
        throw new RuntimeException('تاریخ سفارش معتبر نیست.');
    }
    return food_order_internal_items($from, $to);
}

function food_ticket_api_employees(): array
{
    $query = db()->query("SELECT id, employee_number, national_code, first_name, last_name, full_name, is_active FROM users WHERE employee_number IS NOT NULL AND TRIM(employee_number) <> '' ORDER BY is_active DESC, employee_number");
    return array_map(static function (array $row): array {
        $first = trim((string) ($row['first_name'] ?? ''));
        $last = trim((string) ($row['last_name'] ?? ''));
        if ($first === '' && $last === '') {
            $parts = preg_split('/\s+/u', trim((string) ($row['full_name'] ?? '')), 2) ?: [];
            $first = (string) ($parts[0] ?? '');
            $last = (string) ($parts[1] ?? '');
        }
        return [
            'id' => (int) ($row['id'] ?? 0),
            'pc' => (string) ($row['employee_number'] ?? ''),
            'nat' => (string) ($row['national_code'] ?? ''),
            'first' => $first,
            'last' => $last,
            'active' => (bool) ($row['is_active'] ?? false),
        ];
    }, $query->fetchAll());
}

function food_ticket_normalize_guest_card(mixed $value): string
{
    return strtoupper(preg_replace('/[\s:-]+/', '', trim((string) $value)) ?? '');
}

function food_ticket_api_guest_cards(): array
{
    if (!db_table_exists('food_ticket_guest_cards')) {
        throw new RuntimeException('ابتدا upgrade-1.12-food-ticket-management.sql را روی دیتابیس اجرا کنید.');
    }
    if (!(int) db()->query('SELECT COUNT(*) FROM food_ticket_guest_cards')->fetchColumn()) {
        $insert = db()->prepare('INSERT IGNORE INTO food_ticket_guest_cards (card_uid, guest_name, daily_limit, is_active) VALUES (?, "مهمان", 0, 1)');
        foreach (food_ticket_guest_cards(food_ticket_config()) as $card) {
            $insert->execute([$card]);
        }
    }
    $query = db()->query('SELECT id, card_uid, guest_name, daily_limit, is_active FROM food_ticket_guest_cards ORDER BY is_active DESC, id DESC');
    return array_map(static fn (array $row): array => [
        'id' => (int) ($row['id'] ?? 0),
        'cardNumber' => (string) ($row['card_uid'] ?? ''),
        'name' => (string) ($row['guest_name'] ?? 'مهمان'),
        'dailyLimit' => (int) ($row['daily_limit'] ?? 0),
        'active' => (bool) ($row['is_active'] ?? false),
    ], $query->fetchAll());
}

function food_ticket_sync_guest_card_config(int $userId): void
{
    if (!db_table_exists('food_ticket_guest_cards') || !db_table_exists('food_ticket_config')) {
        return;
    }
    $cards = db()->query('SELECT card_uid FROM food_ticket_guest_cards WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    db()->prepare('UPDATE food_ticket_config SET guest_card_uids = ?, updated_by = ? WHERE id = 1')->execute([implode(',', array_map('strval', $cards)), $userId]);
}

function food_ticket_api_save_employee(array $data): void
{
    $id = max(0, (int) ($data['id'] ?? 0));
    $personnelCode = (string) (normalize_employee_number($data['pc'] ?? $data['employee_number'] ?? '') ?? '');
    $nationalCode = strtr(trim((string) ($data['nat'] ?? $data['national_code'] ?? '')), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    $nationalCode = preg_replace('/\D+/', '', $nationalCode) ?? '';
    $firstName = trim((string) ($data['first'] ?? $data['first_name'] ?? ''));
    $lastName = trim((string) ($data['last'] ?? $data['last_name'] ?? ''));
    if ($personnelCode === '' || $nationalCode === '' || $firstName === '' || $lastName === '') {
        throw new RuntimeException('کد پرسنلی، کد ملی، نام و نام خانوادگی الزامی است.');
    }
    if (strlen($nationalCode) < 10 || strlen($nationalCode) > 30) {
        throw new RuntimeException('کد ملی باید بین ۱۰ تا ۳۰ رقم باشد.');
    }
    $active = array_key_exists('active', $data) ? (!empty($data['active']) ? 1 : 0) : 1;
    $fullName = trim($firstName . ' ' . $lastName);
    $existing = db()->prepare('SELECT id FROM users WHERE employee_number = ? LIMIT 1');
    $existing->execute([$personnelCode]);
    $existingId = (int) ($existing->fetchColumn() ?: 0);
    if ($id > 0 && $existingId > 0 && $existingId !== $id) {
        throw new RuntimeException('این کد پرسنلی قبلاً ثبت شده است.');
    }
    // کد ملی باید یکتا باشد: اگر برای کارمند دیگری ثبت شده، ذخیره نمی‌شود (در ویرایش، خود همین کارمند مستثنا است).
    $ownerStmt = db()->prepare('SELECT id, full_name FROM users WHERE national_code = ? AND id <> ? LIMIT 1');
    $ownerStmt->execute([$nationalCode, $id ?: $existingId]);
    $owner = $ownerStmt->fetch();
    if ($owner) {
        throw new RuntimeException('این کد ملی قبلاً برای کارمند «' . (string) $owner['full_name'] . '» ثبت شده است.');
    }
    $id = $id ?: $existingId;
    if ($id > 0) {
        $currentUser = db()->prepare('SELECT employee_number FROM users WHERE id = ? LIMIT 1');
        $currentUser->execute([$id]);
        if (trim((string) ($currentUser->fetchColumn() ?: '')) === '') {
            throw new RuntimeException('کارمند پیدا نشد.');
        }
        $query = db()->prepare('UPDATE users SET full_name = ?, first_name = ?, last_name = ?, employee_number = ?, national_code = ?, is_active = ? WHERE id = ?');
        $query->execute([$fullName, $firstName, $lastName, $personnelCode, $nationalCode, $active, $id]);
        if ($query->rowCount() < 1) {
            $check = db()->prepare('SELECT id FROM users WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('کارمند پیدا نشد.');
            }
        }
        return;
    }
    $username = 'food_employee_' . substr(hash('sha256', $personnelCode), 0, 40);
    $query = db()->prepare('INSERT INTO users (username, password_hash, full_name, first_name, last_name, employee_number, national_code, role, auth_source, is_active) VALUES (?, NULL, ?, ?, ?, ?, ?, "user", "food", ?)');
    $query->execute([$username, $fullName, $firstName, $lastName, $personnelCode, $nationalCode, $active]);
}

function food_ticket_api_save_guest_card(array $data, int $userId): void
{
    if (!db_table_exists('food_ticket_guest_cards')) {
        throw new RuntimeException('ابتدا upgrade-1.12-food-ticket-management.sql را روی دیتابیس اجرا کنید.');
    }
    $id = max(0, (int) ($data['id'] ?? 0));
    $card = food_ticket_normalize_guest_card($data['cardNumber'] ?? $data['card_uid'] ?? '');
    $name = trim((string) ($data['guestName'] ?? $data['name'] ?? '')) ?: 'مهمان';
    $dailyLimit = max(0, min(10000, (int) ($data['dailyLimit'] ?? 0)));
    $active = array_key_exists('active', $data) ? (!empty($data['active']) ? 1 : 0) : 1;
    if ($card === '' || strlen($card) > 120) {
        throw new RuntimeException('شماره کارت مهمان معتبر نیست.');
    }
    if ($id > 0) {
        $query = db()->prepare('UPDATE food_ticket_guest_cards SET card_uid = ?, guest_name = ?, daily_limit = ?, is_active = ?, updated_by = ? WHERE id = ?');
        $query->execute([$card, $name, $dailyLimit, $active, $userId, $id]);
    } else {
        $query = db()->prepare('INSERT INTO food_ticket_guest_cards (card_uid, guest_name, daily_limit, is_active, updated_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE guest_name = VALUES(guest_name), daily_limit = VALUES(daily_limit), is_active = VALUES(is_active), updated_by = VALUES(updated_by)');
        $query->execute([$card, $name, $dailyLimit, $active, $userId]);
    }
    food_ticket_sync_guest_card_config($userId);
}

function food_ticket_api_delete_guest_card(int $id, int $userId, string $cardNumber = ''): void
{
    if (!db_table_exists('food_ticket_guest_cards')) {
        throw new RuntimeException('کارت مهمان پیدا نشد.');
    }
    if ($id > 0) {
        $stmt = db()->prepare('DELETE FROM food_ticket_guest_cards WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('کارت مهمان پیدا نشد.');
        }
    } else {
        $card = food_ticket_normalize_guest_card($cardNumber);
        if ($card === '') {
            throw new RuntimeException('کارت مهمان پیدا نشد.');
        }
        $stmt = db()->prepare('DELETE FROM food_ticket_guest_cards WHERE UPPER(REPLACE(REPLACE(REPLACE(card_uid, " ", ""), "-", ""), ":", "")) = ? OR card_uid = ?');
        $stmt->execute([$card, $cardNumber]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('کارت مهمان پیدا نشد.');
        }
    }
    food_ticket_sync_guest_card_config($userId);
}

function food_ticket_api_save_config(array $data, array $user): void
{
    // اگر چاپگر ویندوز انتخاب شد، حالت صف ویندوز اولویت دارد
    // اگر از فرم printer.mode آمده، همان مبنا است (سوییچ Windows/TCP)
    $printer = is_array($data['printer'] ?? null) ? $data['printer'] : [];
    if (isset($printer['mode'])) {
        $data['printer_mode'] = food_ticket_normalize_printer_mode($printer['mode']);
    }
    if (isset($printer['name'])) {
        $data['printer_share'] = trim((string) $printer['name']);
    }
    if (isset($printer['host'])) {
        $data['printer_host'] = trim((string) $printer['host']);
    }
    if (isset($printer['port'])) {
        $data['printer_port'] = (int) $printer['port'];
    }
    // اگر IP چاپگر آمده و mode خالی است → tcp_raw
    if (trim((string) ($printer['host'] ?? $data['printer_host'] ?? '')) !== '' && empty($data['printer_mode']) && empty($printer['mode'])) {
        $data['printer_mode'] = 'tcp_raw';
    }

    $current = food_ticket_config();
    $legacySourceKey = function_exists('food_ticket_legacy_key') ? food_ticket_legacy_key('api_object') : '';
    $sourcePayload = is_array($data['attendance'] ?? null) ? $data['attendance'] : (is_array($data[$legacySourceKey] ?? null) ? $data[$legacySourceKey] : []);
    $sourceDb = $sourcePayload;
    $printer = is_array($data['printer'] ?? null) ? $data['printer'] : [];
    $value = static function (array $source, string $key, mixed $fallback): mixed {
        return array_key_exists($key, $source) ? $source[$key] : $fallback;
    };
    // ⚠️ محافظ ۱.۳۵: مقدار خالی هرگز جای مقدار سالمِ ذخیره‌شده را نمی‌گیرد.
    // چرا: اگر پنل (به هر دلیلی، مثلاً کش خالی مرورگر) فرمی با فیلدهای خالی بفرستد،
    // مسیر Access/سفارش و نام چاپگر پاک می‌شد و ماژول دیگر نمی‌توانست به سامانه وصل شود.
    // برای پاک‌کردن عمدی باید clear=1 فرستاده شود.
    $allowClear = !empty($data['clear']) || !empty($data['clear_paths']);
    $keepIfEmpty = static function (string $incoming, mixed $stored) use ($allowClear): string {
        $incoming = trim($incoming);
        if ($incoming !== '') {
            return $incoming;
        }
        return $allowClear ? '' : trim((string) $stored);
    };
    $attendancePath = $keepIfEmpty((string) $value($sourceDb, 'path', $value($data, 'attendancePath', $current['attendance_path'] ?? '')), $current['attendance_path'] ?? '');
    // مسیر/جدول قدیمی سفارش Access از این API خوانده یا تغییر داده نمی‌شود.
    $printerHost = $keepIfEmpty((string) $value($printer, 'host', $value($data, 'printerHost', $current['printer_host'] ?? '')), $current['printer_host'] ?? '');
    $printerShare = $keepIfEmpty((string) $value($printer, 'name', $value($data, 'printerShare', $current['printer_share'] ?? '')), $current['printer_share'] ?? '');
    $printerMode = food_ticket_normalize_printer_mode($value($printer, 'mode', $value($data, 'printer_mode', $current['printer_mode'] ?? 'tcp_raw')));
    $modeExplicit = isset($printer['mode']) && trim((string) $printer['mode']) !== '';
    if ($printerMode === 'windows_share' && $printerShare === '') {
        if ($modeExplicit) {
            throw new RuntimeException('برای چاپ از صف ویندوز باید نام چاپگر را انتخاب کنید.');
        }
        // درخواست قدیمی بدون mode صریح: اگر IP هست، حالت شبکه
        if ($printerHost !== '') {
            $printerMode = 'tcp_raw';
        }
    }
    // اگر UI فیلد enabled نفرستد ولی مسیرها پر باشد → خودکار فعال
    if (array_key_exists('enabled', $data)) {
        $enabledFlag = !empty($data['enabled']) ? 1 : 0;
    } elseif ($attendancePath !== '' && function_exists('food_order_mode') && food_order_mode() === 'INTERNAL-DB') {
        $enabledFlag = 1;
    } else {
        $enabledFlag = !empty($current['enabled']) ? 1 : 0;
    }
    food_ticket_save_config([
        'enabled' => $enabledFlag,
        'attendance_path' => $attendancePath,
        'attendance_password' => $value($sourceDb, 'password', $value($data, 'attendancePassword', '')),
        'printer_host' => $printerHost,
        'printer_port' => $value($printer, 'port', $value($data, 'printerPort', $current['printer_port'] ?? 9100)),
        'printer_share' => $printerShare,
        'printer_mode' => $printerMode,
        'poll_seconds' => max(1, min(60, (int) ($value($data, 'poll_seconds', $value($data, 'pollSeconds', $current['poll_seconds'] ?? 2)) ?: ($current['poll_seconds'] ?? 2)))),
        'max_batch' => $value($data, 'max_batch', $current['max_batch'] ?? 100),
        'guest_card_uids' => $value($data, 'guestCardUIDs', $current['guest_card_uids'] ?? ''),
        'max_guest_daily' => $value($data, 'maxGuestTicketsPerDay', $current['max_guest_daily'] ?? 20),
        'cut_source_rows' => $value($sourceDb, 'cut_source_rows', $value($data, 'cutSourceRows', $current['cut_source_rows'] ?? 1)),
    ], $user);
    if (array_key_exists('guestCapMode', $data)) {
        $guestCapModeIn = (string) ($data['guestCapMode'] ?? '');
        save_setting('food_guest_cap_mode', $guestCapModeIn === 'variable' ? 'variable' : 'requested');
    }
    $capTimeKeys = ['guestCapStart' => 'food_guest_cap_start', 'guestCapCutoff' => 'food_guest_cap_cutoff', 'guestCapEnd' => 'food_guest_cap_end'];
    foreach ($capTimeKeys as $field => $settingKey) {
        if (array_key_exists($field, $data) && preg_match('/^\d{2}:\d{2}$/', (string) $data[$field])) {
            save_setting($settingKey, (string) $data[$field] . ':00');
        }
    }
    if (((string) ($user['role'] ?? '') === 'admin' || (string) ($user['role'] ?? '') === 'primary_admin') && array_key_exists('brandName', $data) && trim((string) $data['brandName']) !== '') {
        save_setting('food_ticket_brand_name', trim((string) $data['brandName']));
        if (array_key_exists('brandLogo', $data)) {
            save_setting('food_ticket_brand_logo', trim((string) $data['brandLogo']));
        }
    }
}


/**
 * باز کردن دیالوگ انتخاب فایل/پوشه روی خود سرور Windows (بدون نیاز به سرویس C#).
 * پنجره روی Desktop همان سرور باز می‌شود.
 */

/**
 * مرورگر مسیر سرور (بدون دیالوگ دسکتاپ — مناسب IIS/Apache به‌صورت سرویس).
 */
function food_ticket_browse_list(?string $path = null, string $mode = 'file'): array
{
    if (strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
        throw new RuntimeException('مرور مسیر فقط روی سرور Windows در دسترس است. مسیر را دستی وارد کنید.');
    }

    $path = $path !== null ? trim(str_replace(['/', "\0"], ['\\', ''], $path)) : '';
    if ($path === '') {
        // لیست درایوها
        $drives = [];
        foreach (range('A', 'Z') as $letter) {
            $root = $letter . ':\\';
            if (@is_dir($root)) {
                $drives[] = ['name' => $letter . ':', 'path' => $root, 'type' => 'drive'];
            }
        }
        // اشتراک‌های رایج شبکه را دستی می‌توان تایپ کرد
        return [
            'path' => '',
            'parent' => null,
            'dirs' => $drives,
            'files' => [],
            'mode' => $mode,
            'message' => 'یک درایو را انتخاب کنید یا مسیر UNC مثل \\\\server\\share را دستی وارد کنید.',
        ];
    }

    // اجازه مسیر UNC
    $real = $path;
    if (!preg_match('/^[A-Za-z]:\\\\/', $path) && !str_starts_with($path, '\\\\')) {
        throw new RuntimeException('مسیر نامعتبر است.');
    }
    if (!@is_dir($real) && !@is_file($real)) {
        throw new RuntimeException('مسیر روی سرور یافت نشد: ' . $path);
    }
    if (@is_file($real)) {
        return [
            'path' => $real,
            'parent' => dirname($real),
            'dirs' => [],
            'files' => [['name' => basename($real), 'path' => $real, 'type' => 'file']],
            'mode' => $mode,
            'selected' => $real,
        ];
    }

    $parent = null;
    if (preg_match('/^[A-Za-z]:\\\\$/', $real)) {
        $parent = '';
    } elseif (str_starts_with($real, '\\\\')) {
        $parts = explode('\\', trim($real, '\\'));
        $parent = count($parts) > 2 ? ('\\\\' . implode('\\', array_slice($parts, 0, -1))) : '';
    } else {
        $parent = dirname($real);
    }

    $dirs = [];
    $files = [];
    $items = @scandir($real) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = rtrim($real, '\\') . '\\' . $item;
        if (@is_dir($full)) {
            $dirs[] = ['name' => $item, 'path' => $full, 'type' => 'dir'];
        } elseif ($mode !== 'folder') {
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, ['mdb', 'accdb', ''], true) || $mode === 'any') {
                $files[] = ['name' => $item, 'path' => $full, 'type' => 'file'];
            }
        }
    }
    usort($dirs, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
    usort($files, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));

    return [
        'path' => $real,
        'parent' => $parent,
        'dirs' => $dirs,
        'files' => $files,
        'mode' => $mode,
    ];
}

function food_ticket_php_native_browse(string $route): array
{
    // سازگاری: اگر body خالی باشد فقط ریشه را برگردان
    $body = [];
    if (function_exists('food_ticket_api_body')) {
        try {
            $body = food_ticket_api_body();
        } catch (Throwable) {
            $body = [];
        }
    }
    $path = isset($body['path']) ? (string) $body['path'] : '';
    $mode = $route === 'browse-folder' ? 'folder' : 'file';
    if (!empty($body['select']) && is_string($body['select'])) {
        $selected = trim(str_replace('/', '\\', $body['select']));
        if ($mode === 'folder' && @is_dir($selected)) {
            return ['path' => $selected];
        }
        if ($mode === 'file' && @is_file($selected)) {
            return ['path' => $selected];
        }
        throw new RuntimeException('مسیر انتخاب‌شده معتبر نیست.');
    }
    return food_ticket_browse_list($path === '' ? null : $path, $mode);
}

function food_ticket_windows_browse(string $route, array $user): array
{
    if (!food_ticket_is_primary_admin($user)) {
        throw new RuntimeException('فقط ادمین اصلی می‌تواند مسیر پایگاه‌ها را انتخاب کند.');
    }
    // مرورگر فایل مبتنی بر لیست پوشه — بدون نیاز به Desktop/Session تعاملی
    return food_ticket_php_native_browse($route);
}


/**
 * وضعیت سلامت پنل — شکل پاسخ سازگار با SPA (renderHealthPayload)
 */
function food_ticket_api_health(): array
{
    $config = food_ticket_config(true);
    $attendancePath = trim((string) ($config['attendance_path'] ?? ''));
    $mode = food_ticket_normalize_printer_mode($config['printer_mode'] ?? 'tcp_raw');
    $host = trim((string) ($config['printer_host'] ?? ''));
    $port = (int) ($config['printer_port'] ?? 9100);
    $name = trim((string) ($config['printer_share'] ?? ''));
    $printerReady = $mode === 'tcp_raw' ? ($host !== '') : ($name !== '' || $host !== '');

    $records = 0;
    $lastEventAt = '';
    $pending = 0;
    $printError = 0;
    $lastError = '';
    try {
        $records = (int) db()->query('SELECT COUNT(*) FROM food_ticket_events')->fetchColumn();
        $pending = (int) db()->query('SELECT COUNT(*) FROM food_ticket_events WHERE print_status IN ("pending","printing")')->fetchColumn();
        $printError = (int) db()->query('SELECT COUNT(*) FROM food_ticket_events WHERE print_status IN ("print_error","failed")')->fetchColumn();
        $last = db()->query('SELECT created_at, last_error FROM food_ticket_events ORDER BY id DESC LIMIT 1')->fetch();
        if ($last) {
            $lastEventAt = (string) ($last['created_at'] ?? '');
            $lastError = (string) ($last['last_error'] ?? '');
        }
    } catch (Throwable $e) {
        $lastError = $e->getMessage();
    }

    $ssoOk = true;
    try {
        $ssoOk = strlen((string) (function_exists('cfg') ? cfg('app.key', 'x') : 'x')) >= 8;
    } catch (Throwable) {
        $ssoOk = false;
    }

    return [
        'service' => 'ready',
        'dotnet' => false, // موتور PHP worker
        'php_worker' => true,
        'database' => [
            'path' => 'mysql:food_ticket_events',
            'writable' => true,
            'records' => $records,
        ],
        'access' => [
            'configured' => $attendancePath !== '',
            'path' => $attendancePath,
            'live' => false,
        ],
        'printer' => [
            'mode' => $mode === 'tcp_raw' ? 'socket' : 'windows-spooler',
            'name' => $name,
            'host' => $host,
            'port' => $port,
            'ready' => $printerReady,
        ],
        'queue' => [
            'pending' => $pending,
            'print_error' => $printError,
            'last_event_at' => $lastEventAt,
        ],
        'sso' => $ssoOk,
        'last_error' => $lastError,
        'enabled' => !empty($config['enabled']),
    ];
}

/**
 * آزمون کامل موتور — معادل SelfTest سرویس ویندوز / منطق runtime
 */
function food_ticket_api_self_test(): array
{
    $checks = [];
    $config = food_ticket_config(true);
    $at = date('c');

    // 1) منطق پارس تاریخ/ساعت و قالب
    if (function_exists('food_ticket_self_test_logic')) {
        $logic = food_ticket_self_test_logic();
        $logicOk = !in_array(false, $logic, true);
        $checks[] = [
            'name' => 'منطق موتور (تاریخ/ساعت/قالب)',
            'ok' => $logicOk,
            'message' => $logicOk ? 'پارس تاریخ/ساعت و قالب فیش سالم است.' : ('خطا در: ' . implode(', ', array_keys(array_filter($logic, static fn ($v) => !$v)))),
            'detail' => $logic,
        ];
    } else {
        $checks[] = ['name' => 'منطق موتور', 'ok' => false, 'message' => 'food-ticket-runtime.php بارگذاری نشد.'];
    }

    // 2) MySQL events
    try {
        $count = (int) db()->query('SELECT COUNT(*) FROM food_ticket_events')->fetchColumn();
        $checks[] = ['name' => 'دیتابیس داخلی مانیتورینگ', 'ok' => true, 'message' => "جدول events قابل خواندن است؛ {$count} رکورد."];
    } catch (Throwable $e) {
        $checks[] = ['name' => 'دیتابیس داخلی مانیتورینگ', 'ok' => false, 'message' => $e->getMessage()];
    }

    // 3) منبع تردد
    $sourceDb = null;
    try {
        $attendancePath = trim((string) ($config['attendance_path'] ?? ''));
        if ($attendancePath === '') {
            throw new RuntimeException('مسیر منبع تردد تنظیم نشده است.');
        }
        $sourceDb = food_ticket_odbc($attendancePath, food_ticket_attendance_password($config));
        food_ticket_access_rows($sourceDb, food_ticket_source_table(), 3);
        $checks[] = ['name' => 'تردد', 'ok' => true, 'message' => 'اتصال موفق است.'];
    } catch (Throwable $e) {
        $checks[] = ['name' => 'تردد', 'ok' => false, 'message' => 'اتصال برقرار نشد.'];
    } finally {
        if (function_exists('food_ticket_access_close')) {
            food_ticket_access_close($sourceDb);
        }
    }

    // 4) بررسی دسترس‌پذیری سفارش‌ها
    try {
        food_order_schema_ensure();
        $checks[] = ['name' => 'سفارش‌ها', 'ok' => true, 'message' => 'بررسی سفارش‌ها موفق بود.'];
    } catch (Throwable $e) {
        $checks[] = ['name' => 'سفارش‌ها', 'ok' => false, 'message' => 'بررسی سفارش‌ها ناموفق بود.'];
    }

    // 5) Printer config (بدون اجبار چاپ فیزیکی — فقط آمادگی)
    $mode = food_ticket_normalize_printer_mode($config['printer_mode'] ?? 'tcp_raw');
    $host = trim((string) ($config['printer_host'] ?? ''));
    $name = trim((string) ($config['printer_share'] ?? ''));
    if ($mode === 'tcp_raw') {
        $ok = $host !== '';
        $msg = $ok ? ("چاپگر شبکه: {$host}:" . (int) ($config['printer_port'] ?? 9100)) : 'آدرس چاپگر شبکه (Host) تنظیم نشده است.';
    } else {
        $ok = $name !== '' || $host !== '';
        $msg = $ok ? ('چاپگر Windows: ' . ($name ?: $host)) : 'نام چاپگر Windows تنظیم نشده است.';
    }
    $checks[] = ['name' => 'چاپگر (تنظیمات)', 'ok' => $ok, 'message' => $msg];

    // 6) enabled flag
    $enabled = !empty($config['enabled']);
    $checks[] = [
        'name' => 'فعال‌بودن پردازش',
        'ok' => $enabled,
        'message' => $enabled ? 'پردازش چاپ فیش فعال است.' : 'پردازش غیرفعال است — مسیرها را ذخیره کنید یا enabled را روشن کنید.',
    ];

    $allOk = true;
    foreach ($checks as $c) {
        if (empty($c['ok'])) {
            $allOk = false;
            break;
        }
    }

    return [
        'ok' => $allOk,
        'checks' => $checks,
        'at' => $at,
        'message' => $allOk ? 'آزمون کامل موفق بود.' : 'برخی بررسی‌ها ناموفق بود.',
    ];
}

function food_ticket_api_upload_access_file(array $user): never
{
    $kind = strtolower(trim((string) ($_POST['kind'] ?? '')));
    if ($kind !== 'attendance') {
        food_ticket_api_json(['error' => 'نوع فایل پشتیبانی نمی‌شود.'], 400);
    }
    $upload = $_FILES['file'] ?? null;
    if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        food_ticket_api_json(['error' => 'فایل دریافت نشد یا بارگذاری ناقص بود.'], 400);
    }
    $originalName = (string) ($upload['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['mdb', 'accdb'], true)) {
        food_ticket_api_json(['error' => 'فقط فایل‌های .mdb و .accdb مجاز هستند.'], 400);
    }
    $size = (int) ($upload['size'] ?? 0);
    if ($size < 1 || $size > 128 * 1024 * 1024) {
        food_ticket_api_json(['error' => 'اندازهٔ فایل باید کمتر از ۱۲۸ مگابایت باشد.'], 413);
    }
    if (!is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        food_ticket_api_json(['error' => 'اعتبار فایل بارگذاری‌شده تأیید نشد.'], 400);
    }
    $dir = APP_ROOT . '/storage/private/food_ticket_access';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        food_ticket_api_json(['error' => 'پوشهٔ امن نگهداری فایل دیتابیس ساخته نشد.'], 500);
    }
    // جلوگیری از دسترسی مستقیم HTTP حتی در استقرارهایی که storage زیر web-root است.
    @file_put_contents($dir . '/.htaccess', "Require all denied\nOptions -Indexes\n");
    @file_put_contents($dir . '/web.config', '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security><directoryBrowse enabled="false"/></system.webServer></configuration>');
    $filename = $kind . '-' . bin2hex(random_bytes(12)) . '.' . $extension;
    $destination = $dir . '/' . $filename;
    if (!@move_uploaded_file((string) $upload['tmp_name'], $destination)) {
        food_ticket_api_json(['error' => 'ذخیرهٔ فایل در پوشهٔ امن سرور ناموفق بود.'], 500);
    }
    @chmod($destination, 0600);
    $realPath = realpath($destination);
    if (!is_string($realPath) || $realPath === '') {
        @unlink($destination);
        food_ticket_api_json(['error' => 'مسیر نهایی فایل روی سرور خوانده نشد.'], 500);
    }
    try {
        $column = food_ticket_db_column('attendance_path');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            throw new RuntimeException('نام ستون مسیر دیتابیس معتبر نیست.');
        }
        $exists = db()->query('SELECT id FROM food_ticket_config WHERE id = 1')->fetchColumn();
        if ($exists === false) {
            throw new RuntimeException('ردیف تنظیمات فیش غذا ساخته نشده است؛ ابتدا تنظیمات را در پنل ذخیره کنید.');
        }
        $stmt = db()->prepare('UPDATE food_ticket_config SET `' . $column . '` = ? WHERE id = 1');
        $stmt->execute([$realPath]);
        $savedPath = db()->query('SELECT `' . $column . '` FROM food_ticket_config WHERE id = 1')->fetchColumn();
        if (!is_string($savedPath) || $savedPath !== $realPath) {
            throw new RuntimeException('مسیر فایل در تنظیمات ذخیره نشد یا با مسیر بارگذاری‌شده یکی نیست.');
        }
        if (function_exists('save_audit')) {
            try {
                save_audit((int) ($user['id'] ?? 0), 'food_ticket_access_database_uploaded', null, ['kind' => $kind, 'filename' => $filename, 'size' => $size]);
            } catch (Throwable $auditError) {
                error_log('[food-ticket] database upload audit failed: ' . $auditError->getMessage());
            }
        }
        food_ticket_config(true);
        food_ticket_api_json(['ok' => true, 'kind' => $kind, 'path' => $realPath, 'message' => 'فایل بارگذاری شد و مسیر سرور ذخیره شد.']);
    } catch (Throwable $e) {
        @unlink($destination);
        food_ticket_api_json(['error' => 'فایل بارگذاری شد اما مسیر در تنظیمات ذخیره نشد: ' . $e->getMessage()], 500);
    }
}

function food_ticket_api_handle(string $route, array $user): never
{
    require_food_ticket_access();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
    }
    // قفل فایل session را همین‌جا آزاد کن: پایش، سلامت، سفارش‌ها و چاپگرها هم‌زمان صدا زده می‌شوند و تا پایان
    // هر درخواست کند (Access/PowerShell) بقیه پشت قفل session صف می‌ماندند؛ همین باعث «اسلوموشن» شدن فرم می‌شد.
    if (function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
    $route = rawurldecode(ltrim(trim($route), '/'));
    if (str_starts_with($route, 'api/')) {
        $route = substr($route, 4);
    }
    // querystring داخل route نماند
    if (str_contains($route, '?')) {
        $route = strstr($route, '?', true) ?: $route;
    }
    if (!food_ticket_route_allowed($user, $route)) {
        food_ticket_api_json(['error' => 'دسترسی شما به این بخش از پنل چاپ فیش مجاز نیست.'], 403);
    }
    if (str_starts_with($route, 'food-menu/')) {
        if (!function_exists('food_order_menu_api_handle')) {
            food_ticket_api_json(['error' => 'ماژول برنامه غذایی بارگذاری نشده است.'], 503);
        }
        food_order_menu_api_handle($route, $user);
    }
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'config') {
            food_ticket_api_json(food_ticket_api_config());
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'diag-source_row-delete') {
            // ابزار تشخیص حذف SOURCE_TABLE — فقط خواندنی؛ با ?delete=1 یک ردیف نمونه واقعاً حذف می‌شود.
            require APP_ROOT . '/tools/diag_source_row_delete.php';
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'monitoring') {
            $fromRaw = isset($_GET['from']) ? (string) $_GET['from'] : null;
            $toRaw = isset($_GET['to']) ? (string) $_GET['to'] : null;
            if (($fromRaw === null) !== ($toRaw === null)) {
                food_ticket_api_json(['error' => 'تاریخ شروع و پایان گزارش باید با هم ارسال شوند.'], 400);
            }
            if ($fromRaw !== null) {
                $from = food_ticket_parse_date($fromRaw);
                $to = food_ticket_parse_date((string) $toRaw);
                if ($from === null || $to === null || $from > $to) {
                    food_ticket_api_json(['error' => 'بازهٔ تاریخ گزارش معتبر نیست.'], 400);
                }
                food_ticket_api_json(['items' => food_ticket_api_monitoring($from, $to), 'from' => $from, 'to' => $to]);
            }
            food_ticket_api_json(['items' => food_ticket_api_monitoring()]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'absent') {
            $legacyDate = (string) ($_GET['date'] ?? $_GET['food_date'] ?? '');
            $fromRaw = isset($_GET['from']) ? (string) $_GET['from'] : ($legacyDate !== '' ? $legacyDate : date('Y-m-d'));
            $toRaw = isset($_GET['to']) ? (string) $_GET['to'] : ($legacyDate !== '' ? $legacyDate : $fromRaw);
            $from = food_ticket_parse_date($fromRaw);
            $to = food_ticket_parse_date($toRaw);
            if ($from === null || $to === null || $from > $to) {
                food_ticket_api_json(['error' => 'بازهٔ تاریخ گزارش معتبر نیست.'], 400);
            }
            food_ticket_api_json(['items' => food_ticket_api_absent($from, $to), 'from' => $from, 'to' => $to]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'guest-requests-report') {
            // گزارش درخواست‌های غذای مهمان مدیران؛ فیلتر معاونت: all یا شناسهٔ معاونت
            $from = food_ticket_parse_date((string) ($_GET['from'] ?? ''));
            $to = food_ticket_parse_date((string) ($_GET['to'] ?? ''));
            if ($from === null || $to === null || $from > $to) {
                food_ticket_api_json(['error' => 'بازهٔ تاریخ گزارش معتبر نیست.'], 400);
            }
            $deputyRaw = trim((string) ($_GET['deputy_id'] ?? 'all'));
            if ($deputyRaw !== 'all' && !ctype_digit($deputyRaw)) {
                food_ticket_api_json(['error' => 'فیلتر معاونت معتبر نیست.'], 400);
            }
            food_ticket_api_json(food_ticket_api_guest_requests_report($from, $to, $deputyRaw));
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'employees') {
            food_ticket_api_json(['items' => food_ticket_api_employees()]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'guest-cards') {
            food_ticket_api_json(['items' => food_ticket_api_guest_cards()]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'guest-cap') {
            // سقف فعلی سقف متغیر + تعداد داخل ساختمان برای باکس نمایش در فرم مهمان
            $nowTime = date('H:i:s');
            $capNow = food_guest_cap_mode() === 'requested'
                ? food_guest_requested_total(date('Y-m-d'))
                : food_ticket_guest_variable_cap(date('Y-m-d'), $nowTime);
            $insideNow = null;
            try {
                $insideNow = (int) db()->query('SELECT COUNT(*) FROM traffic_visits WHERE visit_date = CURDATE() AND exit_time IS NULL')->fetchColumn();
            } catch (Throwable) {
                $insideNow = null;
            }
            $guestCfg = food_ticket_config();
            food_ticket_api_json([
                'mode' => food_guest_cap_mode(),
                'time' => substr($nowTime, 0, 5),
                'cap' => $capNow,
                'inside' => $insideNow,
                'fallback' => (int) ($guestCfg['max_guest_daily'] ?? 0),
            ]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'food-groups') {
            $groupsDate = (string) ($_GET['date'] ?? '');
            if ($groupsDate !== '') {
                $parsedDate = food_ticket_parse_date($groupsDate);
                $groupsDate = $parsedDate ?? '';
            }
            food_ticket_api_json(food_ticket_api_groups($groupsDate));
        }
        // وضعیت غیبت روزانه برای فرم مدیریت گروه/کارکنان (بدون صفحهٔ جداگانه)
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'absence-status') {
            $absenceGroup = (int) ($_GET['group_id'] ?? $_GET['group'] ?? 0);
            // date= شمسی (۱۴۰۵/۰۷/۱۴) یا میلادی (2026-10-06) پذیرفته می‌شود
            $absenceDate = food_ticket_iso_date_input($_GET['date'] ?? '') ?? date('Y-m-d');
            food_ticket_api_json(food_ticket_api_absence_status($absenceGroup, $absenceDate, $user));
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'orders') {
            $reqDate = (string) ($_GET['date'] ?? date('Y-m-d'));
            $reqJalali = trim((string) ($_GET['jalali'] ?? ''));
            $reqTo = trim((string) ($_GET['to'] ?? '')) ?: null;
            food_ticket_api_json(['items' => food_ticket_api_orders($reqDate, $reqJalali, $reqTo)]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'print-diag') {
            food_ticket_api_json(food_ticket_api_print_diag());
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'printers') {
            $config = food_ticket_config();
            $installed = food_ticket_list_windows_printers();
            $saved = trim((string) ($config['printer_share'] ?? ''));
            $items = $installed;
            if ($saved !== '' && !in_array($saved, $items, true)) {
                array_unshift($items, $saved);
            }
            // حالت شبکه مستقیم هم قابل نمایش است
            $host = trim((string) ($config['printer_host'] ?? ''));
            $port = (int) ($config['printer_port'] ?? 9100);
            $tcpLabel = $host !== '' ? ($host . ':' . $port) : '';
            $modeDb = food_ticket_normalize_printer_mode($config['printer_mode'] ?? 'tcp_raw');
            food_ticket_api_json([
                'items' => array_values(array_unique($items)),
                'installed' => $installed,
                'mode' => $modeDb === 'tcp_raw' ? 'socket' : 'windows-spooler',
                'selected' => $saved,
                'tcp' => $tcpLabel,
                'host' => $host,
                'port' => $port,
                'share' => $saved,
                'hint' => $installed
                    ? 'چاپگرهای نصب‌شده روی سرور Windows'
                    : 'چاپگر نصب‌شده پیدا نشد. روی سرور Windows درایور چاپگر را نصب کنید یا حالت IP:9100 را استفاده کنید.',
            ]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($route, ['login', 'sso-login'], true)) {
            food_ticket_api_json(['ok' => true, 'role' => $user['role'] ?? 'manager']);
        }
        $isTestAttendance = function_exists('food_ticket_route_is_test_attendance') && food_ticket_route_is_test_attendance($route);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($route === 'test-connections' || $isTestAttendance)) {
            $config = food_ticket_config(true);
            $result = [
                'ok' => false,
                'message' => '',
                'attendance_ok' => false,
                'orders_ok' => false,
                'printer_ok' => false,
                'attendance_path' => (string) ($config['attendance_path'] ?? ''),
                'enabled' => !empty($config['enabled']),
            ];
            $parts = [];
            $sourceDb = $orders = null;
            try {
                if (trim((string) ($config['attendance_path'] ?? '')) === '') {
                    throw new RuntimeException('تنظیم اتصال تردد کامل نیست.');
                }
                $sourceDb = food_ticket_odbc((string) $config['attendance_path'], food_ticket_attendance_password($config));
                food_ticket_access_rows($sourceDb, food_ticket_source_table(), 3);
                $result['attendance_ok'] = true;
                $parts[] = 'اتصال تردد موفق بود.';
            } catch (Throwable $e) {
                $parts[] = 'اتصال تردد ناموفق بود.';
            } finally {
                food_ticket_access_close($sourceDb);
            }
            try {
                food_order_schema_ensure();
                $result['orders_ok'] = true;
                $parts[] = 'بررسی سفارش‌ها موفق بود.';
            } catch (Throwable $e) {
                $parts[] = 'بررسی سفارش‌ها ناموفق بود.';
            }
            try {
                $host = trim((string) ($config['printer_host'] ?? ''));
                $port = (int) ($config['printer_port'] ?? 9100);
                if ($host !== '' && food_ticket_normalize_printer_mode($config['printer_mode'] ?? 'tcp_raw') === 'tcp_raw') {
                    $errno = 0;
                    $errstr = '';
                    $sock = @fsockopen($host, $port, $errno, $errstr, 3);
                    if ($sock) {
                        fclose($sock);
                        $result['printer_ok'] = true;
                        $parts[] = "چاپگر TCP $host:$port در دسترس است";
                    } else {
                        $parts[] = "چاپگر TCP $host:$port : " . ($errstr ?: "خطا $errno");
                    }
                } else {
                    $parts[] = 'چاپگر TCP تنظیم نشده یا حالت windows_share است (در تست اتصال سوکت بررسی نشد).';
                }
            } catch (Throwable $e) {
                $parts[] = 'چاپگر: ' . $e->getMessage();
            }
            $result['ok'] = $result['attendance_ok'] && $result['orders_ok'];
            $result['message'] = implode(' | ', $parts);
            // ۱.۳۷: فهرست خط‌به‌خط مشکلات تا پنل بتواند شماره‌دار و خوانا نشان دهد
            $result['lines'] = array_values($parts);
            $result['issues'] = [];
            foreach ([
                ['اتصال تردد', !empty($result['attendance_ok'])],
                ['سفارش‌ها', !empty($result['orders_ok'])],
                ['چاپگر فیش', !empty($result['printer_ok'])],
            ] as $pair) {
                if (!$pair[1]) {
                    $result['issues'][] = $pair[0];
                }
            }
            $result['printer_hint'] = !empty($result['printer_ok'])
                ? ''
                : 'چاپگر فیش: در همین صفحه روش چاپ (شبکه/صف ویندوز) و آدرس یا نام چاپگر را ذخیره کنید؛ سپس «چاپ آزمایشی» بزنید. برای سفیدیِ بالای فیش هم کادر «تراز دقیق لبه‌های کاغذ» را ببینید.';
            // فرانت در حالت !r.ok فقط فیلد error را نشان می‌دهد
            if (!$result['ok']) {
                $result['error'] = $result['message'];
            }
            food_ticket_api_json($result, $result['ok'] ? 200 : 400);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'database-file') {
            food_ticket_api_upload_access_file($user);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'config') {
            food_ticket_api_save_config(food_ticket_api_body(), $user);
            // ۱.۳۷.۲: پنل باید همان مقداری را ببیند که واقعاً در پایگاه‌داده نشست؛
            // «آی‌پی پاک می‌شود / روش چاپ برمی‌گردد» بدون این بازخورد قابل تشخیص نبود.
            food_ticket_api_json(['ok' => true] + food_ticket_api_printer_state());
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($route, ['browse-file', 'browse-folder', 'browse-list'], true)) {
            food_ticket_api_json(food_ticket_windows_browse($route === 'browse-list' ? ((food_ticket_api_body()['mode'] ?? '') === 'folder' ? 'browse-folder' : 'browse-file') : $route, $user));
        }
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true) && $route === 'orders-import' && function_exists('food_order_import_handle')) {
            // فرم ایمپورت سفارش‌های قبلی (Access/Excel/CSV) → جدول داخلی food_orders
            food_order_import_handle($user);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'employees-import') {
            // ورودی multipart است (نه JSON)؛ فایل در $_FILES و گزینه‌ها در $_POST.
            food_ticket_employees_import_handle($user);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'employees') {
            $body = food_ticket_api_body();
            if (($body['action'] ?? '') === 'deactivate') {
                $id = max(0, (int) ($body['id'] ?? 0));
                if ($id < 1) {
                    throw new RuntimeException('کارمند پیدا نشد.');
                }
                $target = db()->prepare('SELECT role, is_primary_admin FROM users WHERE id = ? LIMIT 1');
                $target->execute([$id]);
                $row = $target->fetch() ?: [];
                if (in_array((string) ($row['role'] ?? ''), ['primary_admin'], true) || (int) ($row['is_primary_admin'] ?? 0) === 1) {
                    throw new RuntimeException('امکان غیرفعال کردن مدیر سامانه از این بخش وجود ندارد.');
                }
                db()->prepare('UPDATE users SET is_active = 0 WHERE id = ? AND employee_number IS NOT NULL AND role <> "admin"')->execute([$id]);
            } else {
                food_ticket_api_save_employee($body);
            }
            food_ticket_api_json(['ok' => true, 'items' => food_ticket_api_employees()]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'guest-cards') {
            $body = food_ticket_api_body();
            if (($body['action'] ?? '') === 'delete') {
                food_ticket_api_delete_guest_card((int) ($body['id'] ?? 0), (int) ($user['id'] ?? 0), (string) ($body['cardNumber'] ?? $body['card_uid'] ?? ''));
            } else {
                food_ticket_api_save_guest_card($body, (int) ($user['id'] ?? 0));
            }
            food_ticket_api_json(['ok' => true, 'items' => food_ticket_api_guest_cards()]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'food-groups') {
            $body = food_ticket_api_body();
            if (($body['action'] ?? '') === 'delete') {
                food_ticket_api_delete_group((int) ($body['id'] ?? 0), (int) ($user['id'] ?? 0));
            } else {
                food_ticket_api_save_group($body, (int) ($user['id'] ?? 0));
            }
            food_ticket_api_json(['ok' => true] + food_ticket_api_groups());
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'process') {
            // ستون‌های «وضعیت واقعی حذف منبع» یک‌بار پیش از پردازش تضمین می‌شوند (Migration 1.33).
            if (function_exists('food_ticket_events_ensure_source_delete')) {
                try {
                    food_ticket_events_ensure_source_delete();
                } catch (Throwable $e) {
                    error_log('[food-source-delete] ensure failed: ' . $e->getMessage());
                }
            }
            $summary = food_ticket_process_batch();
            if (function_exists('food_ticket_process_print_queue')) {
                try {
                    $q = food_ticket_process_print_queue_locked(food_ticket_config(true));
                    $summary['queue_print'] = $q;
                    $summary['printed'] = (int) ($summary['printed'] ?? 0) + (int) ($q['printed'] ?? 0);
                } catch (Throwable $e) {
                    $summary['queue_error'] = $e->getMessage();
                }
            }
            if (!empty($summary['group_unknown'])) {
                $summary['group_warning'] = 'تردد با L_UID ناشناخته ثبت شد: ' . (string) ($summary['group_unknown_uid'] ?? '') . ' (بدون فیش گروهی)';
            }
            if (empty($summary['message'])) {
                $summary['message'] = sprintf(
                    'SOURCE_TABLE→پردازش: %d | صف:%d | چاپ:%d | بدون‌غذا:%d | ناشناس:%d | مهمان:%d | تکرار:%d | خطا:%d',
                    (int) ($summary['processed'] ?? 0),
                    (int) ($summary['queued'] ?? 0),
                    (int) ($summary['printed'] ?? 0),
                    (int) ($summary['no_food'] ?? 0),
                    (int) ($summary['unknown'] ?? 0),
                    (int) ($summary['guest'] ?? 0),
                    (int) ($summary['repeat'] ?? 0),
                    (int) ($summary['errors'] ?? 0)
                );
                if (!empty($summary['group_runs'])) {
                    $summary['message'] .= sprintf(
                        ' | گروه:%d | غایب:%d | ثبت‌ناشده(L_UID):%d',
                        (int) $summary['group_runs'],
                        (int) ($summary['absent'] ?? 0),
                        (int) ($summary['group_unknown'] ?? 0)
                    );
                }
            }
            food_ticket_api_json($summary);
        }
        // ثبت غیبت روزانه (بخشی از فرم گروه‌ها) — قفل بعد از اولین تردد معتبر نماینده
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'absence-save') {
            $body = food_ticket_api_body();
            food_ticket_api_json(food_ticket_api_absence_save($body, $user));
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'absence-clear') {
            $body = food_ticket_api_body();
            $groupId = (int) ($body['group_id'] ?? $body['groupId'] ?? 0);
            $date = food_ticket_iso_date_input($body['date'] ?? '') ?? date('Y-m-d');
            food_ticket_api_json(food_ticket_api_absence_clear($groupId, $date, trim((string) ($body['reason'] ?? '')), $user));
        }
        // ثبت تحویل غذا: مستقل از چاپ؛ فیش تحویل‌شده دوباره چاپ نمی‌شود
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'delivery') {
            $body = food_ticket_api_body();
            $action = ((string) ($body['action'] ?? 'deliver')) === 'undeliver' ? 'undeliver' : 'deliver';
            if ($action === 'undeliver' && !food_ticket_can_override_absence($user)) {
                food_ticket_api_json(['error' => 'لغو تحویل فقط با دسترسی سطح بالاتر امکان دارد.'], 403);
            }
            food_ticket_api_json(food_ticket_api_delivery((int) ($body['id'] ?? $body['event_id'] ?? 0), $action, trim((string) ($body['reason'] ?? '')), $user));
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'cleanup-source_row') {
            // پاک‌سازی ردیف‌های باقی‌مانده در SOURCE_TABLE برای فیش‌های ثبت‌شده (dry_run=1 فقط تشخیص).
            $body = food_ticket_api_body();
            food_ticket_api_json(food_ticket_cleanup_source_rows(
                (int) ($body['days'] ?? 7),
                (bool) (int) ($body['dry_run'] ?? 0),
                isset($body['limit']) ? (int) $body['limit'] : null
            ));
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'reprint-errors') {
            if (!(int) (food_ticket_config(true)['enabled'] ?? 0)) {
                food_ticket_api_json(['error' => 'چاپ فیش غیرفعال است.'], 409);
            }
            $result = food_ticket_retry_failed();
            food_ticket_api_json([
                'printed' => $result['ok'],
                'queued' => (int) ($result['queued'] ?? 0),
                'failed' => $result['failed'],
                'deleted' => 0,
                // فقط فیش‌های چاپ‌نشده/خطادار دوباره در صف رفته‌اند؛ فیش‌های چاپ‌شده و تحویل‌شده دست‌نخورده‌اند.
                'queue' => $result['queue'] ?? [],
            ]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'reports/export') {
            throw new RuntimeException('خروجی گزارش از همین مرورگر ساخته می‌شود.');
        }
        
        if (($route === 'templates' || str_starts_with($route, 'templates/')) && function_exists('food_ticket_templates_api')) {
            food_ticket_templates_api($route, $user);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'template') {
            food_ticket_api_json(['template' => function_exists('food_ticket_template') ? food_ticket_template() : []]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'template') {
            if (!food_ticket_is_primary_admin($user)) {
                throw new RuntimeException('ذخیره قالب فیش فقط برای ادمین اصلی مجاز است.');
            }
            $body = food_ticket_api_body();
            $tpl = is_array($body['template'] ?? null) ? $body['template'] : $body;
            food_ticket_save_template($tpl);
            food_ticket_print_log(null, null, null, null, null, 'template_saved', 0, 'by user ' . (int) $user['id']);
            food_ticket_api_json(['ok' => true, 'template' => food_ticket_template()]);
        }
        if ($route === 'calibrate-print') {
            // کارت کالیبراسیون: خط‌کش میلی‌متری که از ردیف صفر شروع می‌شود (مبنای اندازه‌گیری head_offset_mm)
            $calCfg = food_ticket_np_settings();
            $card = food_ticket_np_calibration_print($calCfg);
            if (function_exists('system_log')) {
                system_log('info', 'food-printer', 'چاپ کارت کالیبراسیون', [
                    'ok' => $card['ok'],
                    'bytes' => $card['bytes'],
                    'printer' => $card['host'] . ':' . $card['port'],
                ]);
            }
            $state = food_ticket_np_align_state($calCfg);
            food_ticket_api_json([
                'ok' => $card['ok'],
                'message' => $card['message'],
                'printer' => $card['host'] . ':' . $card['port'],
                'bytes' => $card['bytes'],
                'align' => $state,
                'suggested' => [
                    'head_offset_mm' => $state['head_offset_mm'] > 0 ? $state['head_offset_mm'] : 20.0,
                    'step_mm' => 0.5,
                ],
            ], $card['ok'] ? 200 : 409);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'employees-sample') {
            // دانلود نمونهٔ فایل کارکنان از خود سرور (لینک «⬇️ دانلود نمونهٔ فایل اکسل» در پنل).
            // چرا از سرور؟ چون پنل هم می‌تواند داخل index.php?page=food-ticket میزبانی شود و هم
            // مستقل؛ مسیر نسبی در حالت اول به پوشهٔ برنامه نمی‌رسد. این مسیر در هر دو حالت کار می‌کند.
            $format = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
            $format = $format === 'csv' ? 'csv' : 'xlsx';
            $file = $format === 'csv' ? 'employees-sample.csv' : 'employees-sample.xlsx';
            $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
            $path = $root . '/food-ticket-web/samples/' . $file;
            if (!is_file($path)) {
                food_ticket_api_json(['error' => 'نمونهٔ فایل روی سرور پیدا نشد: food-ticket-web/samples/' . $file], 404);
            }
            if (function_exists('system_log')) {
                system_log('info', 'food-employees', 'دانلود نمونهٔ فایل کارکنان', ['file' => $file, 'by' => (int) ($user['id'] ?? 0)]);
            }
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            $download = 'نمونه-لیست-کارکنان.' . $format;
            header('Content-Type: ' . ($format === 'csv'
                ? 'text/csv; charset=utf-8'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
            header("Content-Disposition: attachment; filename=\"employees-sample.$format\"; filename*=UTF-8''" . rawurlencode($download));
            header('Content-Length: ' . (string) filesize($path));
            header('Cache-Control: private, max-age=0, no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;
        }

        if ($route === 'print-align') {
            // GET → وضعیت فعلی | POST → ذخیرهٔ مقادیر (test=1 ⇒ بلافاصله کارت کالیبراسیون چاپ شود)
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                food_ticket_api_json(['ok' => true, 'align' => food_ticket_np_align_state()]);
            }
            $payload = food_ticket_api_body();
            $patch = [];
            foreach (['head_offset_mm', 'edge_align', 'tail_mm', 'line_dots', 'reverse_feed_cmd', 'cut_mode', 'top_gap_mm', 'fit_bottom', 'feed_lines', 'threshold', 'win_top_shift_mm', 'dots_per_mm'] as $key) {
                if (array_key_exists($key, $payload)) {
                    $patch[$key] = $payload[$key];
                }
            }
            if ($patch === []) {
                food_ticket_api_json(['ok' => false, 'error' => 'هیچ مقدار معتبری برای ذخیره فرستاده نشد.'], 422);
            }
            $written = food_ticket_np_write_json($patch);
            if ($written['error'] !== '') {
                food_ticket_api_json(['ok' => false, 'error' => $written['error']], 500);
            }
            $state = food_ticket_np_align_state();
            $message = $state['hint'];
            if (!empty($payload['test'])) {
                $card = food_ticket_np_calibration_print();
                $message .= ' ' . $card['message'];
            }
            if (function_exists('system_log')) {
                system_log('info', 'food-printer', 'ذخیرهٔ تنظیمات تراز چاپ', [
                    'keys' => array_keys($patch),
                    'active' => $state['active'],
                    'head_offset_mm' => $state['head_offset_mm'],
                ]);
            }
            food_ticket_api_json([
                'ok' => true,
                'message' => $message,
                'file' => $written['file'],
                'saved' => array_intersect_key($written['saved'], $patch),
                'align' => $state,
                'printer' => food_ticket_np_target()['host'],
            ]);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'test-print') {
            $config = food_ticket_config(true);
            $body = food_ticket_api_body();
            $printerBody = is_array($body['printer'] ?? null) ? $body['printer'] : $body;
            if (isset($printerBody['mode'])) {
                $config['printer_mode'] = food_ticket_normalize_printer_mode($printerBody['mode']);
            }
            if (isset($printerBody['host'])) {
                $config['printer_host'] = trim((string) $printerBody['host']);
            }
            if (isset($printerBody['port'])) {
                $config['printer_port'] = max(1, (int) $printerBody['port']);
            }
            if (isset($printerBody['name']) || isset($printerBody['share'])) {
                $config['printer_share'] = trim((string) ($printerBody['name'] ?? $printerBody['share'] ?? ''));
            }
            $sample = [
                'id' => 1001,
                'department' => 'واحد آزمایشی',
                'full_name' => 'نمونه تست',
                'national_code' => '0000000000',
                'personnel_code' => 'TEST',
                'food_type' => 'فیش آزمایشی',
                'punch_date' => date('Y-m-d'),
                'punch_time' => date('H:i:s'),
                'ticket_key' => 'test:' . date('YmdHis'),
                'event_type' => 'printed',
            ];
            // «چاپ آزمایشی» می‌تواند یک قالب مشخص را (بدون فعال‌کردن آن) امتحان کند؛ بدون template_id قالب فعال چاپ می‌شود.
            $forceTpl = (int) ($body['template_id'] ?? 0);
            if ($forceTpl > 0 && function_exists('food_ticket_tpl_force')) {
                food_ticket_tpl_force($forceTpl);
            }
            try {
                food_ticket_send_to_printer($sample, $config);
            } finally {
                if (function_exists('food_ticket_tpl_force')) {
                    food_ticket_tpl_force(null);
                }
            }
            $mode = food_ticket_normalize_printer_mode($config['printer_mode'] ?? 'tcp_raw');
            $printerLabel = $mode === 'tcp_raw'
                ? (trim((string) ($config['printer_host'] ?? '')) . ':' . (int) ($config['printer_port'] ?? 9100))
                : (trim((string) ($config['printer_share'] ?? '')) ?: trim((string) ($config['printer_host'] ?? '')));
            food_ticket_print_log(null, 'test-print', null, $sample['ticket_key'], $printerLabel, 'test_print', 0, 'ok');
            food_ticket_api_json([
                'ok' => true,
                'message' => 'فیش آزمایشی ارسال شد → ' . ($printerLabel !== '' && $printerLabel !== ':' ? $printerLabel : '(چاپگر تنظیم نشده)') . ' | حالت: ' . $mode,
                'mode' => $mode,
                'target' => $printerLabel,
            ]);
        }
        

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'session') {
            food_ticket_api_json([
                'ok' => true,
                'authenticated' => true,
                'role' => (string) ($user['role'] ?? ''),
                'name' => (string) ($user['full_name'] ?? $user['username'] ?? ''),
            ]);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($route, ['login', 'sso-login'], true)) {
            // در حالت یکپارچه با تیکتینگ، session از قبل معتبر است
            food_ticket_api_json([
                'ok' => true,
                'role' => (string) ($user['role'] ?? 'manager'),
                'authenticated' => true,
            ]);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'health') {
            food_ticket_api_json(food_ticket_api_health());
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'self-test') {
            food_ticket_api_json(food_ticket_api_self_test());
        }

        food_ticket_api_json(['error' => 'مسیر API چاپ فیش پیدا نشد.'], 404);
    } catch (Throwable $exception) {
        food_ticket_api_json(['error' => $exception->getMessage()], 400);
    }
}

function food_ticket_render_page(array $user): void
{
    require_food_ticket_access();
    $file = APP_ROOT . '/food-ticket-web/index.html';
    $html = is_file($file) ? file_get_contents($file) : false;
    if (!is_string($html) || $html === '') {
        http_response_code(500);
        error_log('food-ticket: food-ticket-web/index.html پیدا نشد یا خالی است: ' . $file);
        exit('رابط کامل چاپ فیش پیدا نشد. فایل food-ticket-web/index.html را بررسی کنید.');
    }
    $apiBase = 'index.php?page=food-ticket&food_api=';
    $brand = food_ticket_brand_params();
    $nonce = htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8');
    $bootstrap = function_exists('food_ticket_web_host_bootstrap')
        ? food_ticket_web_host_bootstrap($apiBase, csrf_token(), is_array($brand) ? $brand : [], (string) ($user['role'] ?? ''), food_ticket_is_primary_admin($user), $nonce, function_exists('db_install_state') ? db_install_state() : null)
        : '';
    $canMenuEdit = function_exists('user_can') ? user_can($user, 'food.menu_edit') : food_ticket_is_primary_admin($user);
    $canOrderClose = function_exists('user_can') ? user_can($user, 'food.order_close') : food_ticket_is_primary_admin($user);
    $canFoodMenu = $canMenuEdit || $canOrderClose;
    // دسترسی هر بخش پنل از روی کدهای food.* (ادمین اصلی همه‌چیز). سرور هم هر درخواست را جداگانه کنترل می‌کند.
    $fpIsPrimary = food_ticket_is_primary_admin($user);
    $fpCan = static fn (string $code): bool => $fpIsPrimary || (function_exists('user_can') && user_can($user, $code));
    $fpViews = [
        'dashboard' => $fpCan('food.dashboard'),
        'live' => $fpCan('food.monitor'),
        'employees' => $fpCan('food.employees'),
        'food-menu' => $fpCan('food.menu') || $fpCan('food.order_close'),
        'food-groups' => $fpCan('food.groups'),
        'reports' => $fpCan('food.reports'),
        'settings' => $fpCan('food.db') || $fpCan('food.printer') || $fpCan('food.guest') || $fpCan('food.design') || $fpCan('food.health'),
    ];
    $fpSections = [
        'connections' => $fpCan('food.db'),
        'printer' => $fpCan('food.printer'),
        'guests' => $fpCan('food.guest'),
        'ticket-design' => $fpCan('food.design'),
        'health' => $fpCan('food.health'),
    ];
    $fpGroupsEdit = $fpCan('food.groups_edit');
    $bootstrap .= '<script nonce="' . $nonce . '">window.FOOD_TICKET_VIEWS=' . json_encode($fpViews) . ';window.FOOD_TICKET_SECTIONS=' . json_encode($fpSections)
        . ';window.FOOD_TICKET_CAN_GROUPS_EDIT=' . ($fpGroupsEdit ? 'true' : 'false')
        . ';window.FOOD_TICKET_CAN_FOOD_MENU=' . ($canFoodMenu ? 'true' : 'false')
        . ';window.FOOD_TICKET_CAN_MENU_EDIT=' . ($canMenuEdit ? 'true' : 'false')
        . ';window.FOOD_TICKET_CAN_ORDER_CLOSE=' . ($canOrderClose ? 'true' : 'false')
        . ';window.FOOD_TICKET_IS_PRIMARY_ADMIN=' . (food_ticket_is_primary_admin($user) ? 'true' : 'false') . ';</script>';
    // برچسب‌های قدیمی (نام‌های نسخه‌های پیشین) در متن نمایش داده نشوند:
    // نگاشت از لایهٔ محیطی می‌آید تا سورس سامانه هیچ نام برندی نداشته باشد.
    $legacyLabels = function_exists('food_ticket_legacy_display_map') ? food_ticket_legacy_display_map() : [];
    foreach ($legacyLabels as $legacy => $replacement) {
        $html = str_replace((string) $legacy, (string) $replacement, $html);
    }
    $holidayJson = '[]';
    try {
        $__h = db()->query('SELECT holiday_date, title FROM holidays WHERE is_active = 1')->fetchAll();
        $__out = [];
        foreach ($__h as $__row) {
            $g = (string) $__row['holiday_date'];
            $parts = explode('-', substr($g, 0, 10));
            if (count($parts) === 3) {
                [$gy, $gm, $gd] = [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
                [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);
                $__out[] = ['date' => $g, 'jalali' => sprintf('%04d/%02d/%02d', $jy, $jm, $jd), 'title' => (string) $__row['title']];
            }
        }
        $holidayJson = json_encode($__out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    } catch (Throwable) {
        $holidayJson = '[]';
    }
    $jalaliBoot = '<script nonce="' . $nonce . '">window.ITSM_HOLIDAYS=' . $holidayJson . ';</script><script src="assets/jalali-calendar.js?v=2"></script>';

    // میزبانی مقاوم: نام دارایی‌های پنل از خود پوشه خوانده می‌شود تا نسخه‌های تازهٔ index.html
    // (شمارهٔ نسخه/نام فایل متفاوت) هم بدون تغییر کد PHP درست سرو شوند.
    $assetNames = [];
    $assetDir = APP_ROOT . '/food-ticket-web/assets';
    if (is_dir($assetDir)) {
        foreach ((array) scandir($assetDir) as $entry) {
            $entry = (string) $entry;
            if ($entry !== '' && $entry[0] !== '.' && is_file($assetDir . '/' . $entry)) {
                $assetNames[] = $entry;
            }
        }
    }
    if (function_exists('food_ticket_web_host_html')) {
        $hosted = food_ticket_web_host_html($html, [
            'nonce' => $nonce,
            'bootstrap' => $bootstrap,
            'jalali_boot' => $jalaliBoot,
            'assets' => $assetNames,
            'theme_href' => 'assets/style.css?v=3',
        ]);
        $html = (string) ($hosted['html'] ?? $html);
        $notes = (array) ($hosted['notes'] ?? []);
        if (!in_array('bootstrap:brand', $notes, true) && !in_array('bootstrap:already', $notes, true)) {
            error_log('food-ticket: لنگرهای index.html تغییر کرده‌اند؛ بوت‌استرپ از مسیر جایگزین تزریق شد (' . implode(',', $notes) . ')');
        }
        if (!$assetNames) {
            error_log('food-ticket: پوشهٔ food-ticket-web/assets خالی یا ناموجود است؛ دارایی‌های پنل ناقص کپی شده‌اند.');
        }
    }
    echo $html;
    return;
}

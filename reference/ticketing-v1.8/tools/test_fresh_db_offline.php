<?php
declare(strict_types=1);

/**
 * آزمون آفلاین «تفکیک دیتابیس خام» — نسخهٔ ۱.۳۵
 * ============================================================================
 *   php tools/test_fresh_db_offline.php
 *
 * چه چیزی را می‌سنجد؟
 *   ۱) db_install_state روی دیتابیس خالی → fresh=true و توکن ساخته/ذخیره می‌شود
 *   ۲) دیتابیس دارای رکورد → fresh=false و توکن پایدار می‌ماند
 *   ۳) توکن دیتابیس در پاسخ /api/config قرار می‌گیرد (کلید db)
 *   ۴) بوت‌استرپ پنل، FOOD_TICKET_DB_TOKEN و FOOD_TICKET_DB_FRESH را تزریق می‌کند
 *   ۵) سمت مرورگر: گارد دورریز کش در food-ticket-web/index.html وجود دارد
 *   ۶) SQL نصبِ دیتابیس خام ساخته شده و توکن یگانه تولید می‌کند
 *
 * (تست عملی سمت مرورگر جداگانه با Node اجرا می‌شود: tools/test_fresh_state_node.js)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo '[PASS] ' . $label . "\n";
        return;
    }
    $fail++;
    echo '[FAIL] ' . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
}

function grabFunction(string $file, string $name): string
{
    $src = (string) file_get_contents($file);
    $start = strpos($src, 'function ' . $name . '(');
    if ($start === false) {
        throw new RuntimeException('تابع پیدا نشد: ' . $name . ' در ' . $file);
    }
    $brace = strpos($src, '{', $start);
    $depth = 0;
    for ($i = $brace; $i < strlen($src); $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException('بدنهٔ تابع بسته نشد: ' . $name);
}

/* ───────── PDO جعلی برای سناریوهای دیتابیس ───────── */
final class FakeStmt
{
    public function __construct(private array $rows = [], private mixed $column = false)
    {
    }

    public function fetchAll(int $mode = 0): array
    {
        return $this->rows;
    }

    public function fetchColumn(): mixed
    {
        return $this->column;
    }

    public function execute(array $params = []): bool
    {
        return true;
    }
}

final class FreshFakeDb
{
    /** @var array<string,int> تعداد رکورد هر جدول */
    public array $counts = [];
    /** @var array<string,string> تنظیمات */
    public array $settings = [];
    public array $tables = [];

    public function query(string $sql): FakeStmt
    {
        if (preg_match('/^SELECT COUNT\(\*\) FROM `([a-z_]+)`$/i', trim($sql), $m) === 1) {
            return new FakeStmt([], (string) ($this->counts[$m[1]] ?? 0));
        }
        return new FakeStmt([]);
    }

    public function prepare(string $sql, array $options = []): FakeStmt
    {
        return new FakeStmt([], false);
    }
}

$GLOBALS['freshDb'] = new FreshFakeDb();
function db(): FreshFakeDb
{
    return $GLOBALS['freshDb'];
}
function db_table_exists(string $table): bool
{
    return in_array($table, $GLOBALS['freshDb']->tables, true);
}
function setting(string $key, ?string $default = null): ?string
{
    return $GLOBALS['freshDb']->settings[$key] ?? $default;
}
function save_setting(string $key, string $value): void
{
    $GLOBALS['freshDb']->settings[$key] = $value;
}
function system_log(string $level, string $context, string $message, array $meta = []): void
{
}

$root = dirname(__DIR__);
$bootstrap = $root . '/bootstrap.php';
eval(grabFunction($bootstrap, 'db_install_token'));
eval(grabFunction($bootstrap, 'db_install_token_forget'));
eval(grabFunction($bootstrap, 'db_install_fresh_tables'));
eval(grabFunction($bootstrap, 'db_install_state'));

echo "=== آزمون تفکیک دیتابیس خام (سمت سرور) ===\n\n";

/* ───────── ۱) دیتابیس خام ───────── */
$GLOBALS['freshDb'] = new FreshFakeDb();
$GLOBALS['freshDb']->tables = ['users', 'tickets', 'food_ticket_events', 'food_ticket_config'];
$GLOBALS['freshDb']->counts = ['users' => 0, 'tickets' => 0, 'food_ticket_events' => 0, 'food_ticket_config' => 0];
$state = db_install_state(true);
check('۱: دیتابیس خالی → fresh=true', $state['fresh'] === true, json_encode($state, JSON_UNESCAPED_UNICODE));
check('۱-ب: توکن نصب هنگام نیاز ساخته و ذخیره می‌شود', $state['token'] !== '' && (($GLOBALS['freshDb']->settings['db_install_token'] ?? '') === $state['token']), $state['token']);
check('۱-پ: شمارندهٔ رکوردها گزارش می‌شود', isset($state['counts']['tickets']) && $state['counts']['tickets'] === 0);

/* ───────── ۲) دیتابیس دارای رکورد ───────── */
$GLOBALS['freshDb'] = new FreshFakeDb();
$GLOBALS['freshDb']->tables = ['users', 'tickets'];
$GLOBALS['freshDb']->counts = ['users' => 4, 'tickets' => 12];
$GLOBALS['freshDb']->settings = ['db_install_token' => 'tok-existing'];
$state2 = db_install_state(true);
check('۲: دیتابیس دارای رکورد → fresh=false', $state2['fresh'] === false);
check('۲-ب: توکن موجود دست‌نخورده می‌ماند (پایدار)', $state2['token'] === 'tok-existing', $state2['token']);
check('۲-پ: مجموع رکوردها درست محاسبه می‌شود', array_sum($state2['counts']) === 16, json_encode($state2['counts']));

/* ───────── ۳) نبود جدول‌ها (سرور نصب‌نشده) ───────── */
$GLOBALS['freshDb'] = new FreshFakeDb();
$GLOBALS['freshDb']->tables = [];
$state3 = db_install_state(true);
check('۳: سرور نصب‌نشده (بدون جدول) → fresh=true و بدون خطا', $state3['fresh'] === true && $state3['existing'] === false);

/* ───────── ۴) پاسخ /api/config کلید db دارد ───────── */
$foodTicket = (string) file_get_contents($root . '/food-ticket.php');
check('۴: food_ticket_api_config کلید db را برمی‌گرداند', preg_match("/return \\[\\s*\\n?\\s*'db' => \\\$dbState,/", $foodTicket) === 1);
check('۴-ب: پاسخ /api/config از db_install_state تغذیه می‌شود', strpos($foodTicket, 'db_install_state()') !== false);

/* ───────── ۵) بوت‌استرپ پنل متغیرهای دیتابیس را تزریق می‌کند ───────── */
$hostFile = $root . '/food-ticket-web-host.php';
$host = (string) file_get_contents($hostFile);
check('۵: food_ticket_web_host_bootstrap پارامتر dbState دارد', strpos($host, 'string $nonce, ?array $dbState = null') !== false);
$html = food_ticket_web_host_bootstrap_standalone($hostFile, 'index.php?page=food-ticket&food_api=', 'csrf', ['brand_name' => 'تست'], 'admin', true, 'n1', ['token' => 'tok-1', 'fresh' => false]);
check('۵-ب: FOOD_TICKET_DB_TOKEN تزریق می‌شود', strpos($html, 'window.FOOD_TICKET_DB_TOKEN="tok-1"') !== false);
check('۵-پ: FOOD_TICKET_DB_FRESH=false در حالت پر', strpos($html, 'window.FOOD_TICKET_DB_FRESH=false') !== false);
$htmlFresh = food_ticket_web_host_bootstrap_standalone($hostFile, 'b', 'c', [], 'admin', false, 'n2', ['token' => 'tok-2', 'fresh' => true]);
check('۵-ت: FOOD_TICKET_DB_FRESH=true در دیتابیس خام', strpos($htmlFresh, 'window.FOOD_TICKET_DB_FRESH=true') !== false);
check('۵-ث: بدون dbState، خروجی بدون متغیرهای دیتابیس است', strpos($host, 'is_array($dbState)') !== false);

/* ───────── ۶) گارد سمت مرورگر ───────── */
$panel = (string) file_get_contents($root . '/food-ticket-web/index.html');
check('۶: تابع resetLocalState در پنل وجود دارد', strpos($panel, 'function resetLocalState()') !== false);
check('۶-ب: تابع applyDbIdentity وجود دارد', strpos($panel, 'function applyDbIdentity(db)') !== false);
check('۶-پ: گارد توکن در بارگذاری state هست', strpos($panel, 'savedToken!==dbBoot.token') !== false);
check('۶-ت: در startPanel پاسخ /api/config پردازش می‌شود', strpos($panel, 'applyDbIdentity(cfg&&cfg.db)') !== false);
check('۶-ث: توکن دیتابیس همراه کش ذخیره می‌شود (__dbToken)', strpos($panel, "state.__dbToken=dbBoot.token") !== false);

/* ───────── ۷) SQL دیتابیس خام ───────── */
$sqlCandidates = [
    $root . '/install-empty-db.sql',
    dirname($root) . '/خروجی-کامل-نصب-اولیه/02-دیتابیس/install-empty-db.sql',
];
$sqlPath = '';
foreach ($sqlCandidates as $candidate) {
    if (is_file($candidate)) {
        $sqlPath = $candidate;
        break;
    }
}
if ($sqlPath === '') {
    check('۷: فایل install-empty-db.sql ساخته شده است', false, 'در هیچ‌کدام از مسیرهای موردانتظار نبود');
} else {
    $sql = (string) file_get_contents($sqlPath);
    check('۷: SQL دیتابیس خام موجود است', true, $sqlPath);
    check('۷-ب: همهٔ ۵۸ جدول سامانه را می‌سازد', substr_count($sql, 'CREATE TABLE IF NOT EXISTS') >= 58, (string) substr_count($sql, 'CREATE TABLE IF NOT EXISTS'));
    check('۷-پ: توکن نصب را یگانه (UUID) می‌سازد', strpos($sql, 'REPLACE(UUID(),') !== false && strpos($sql, 'db_install_token') !== false);
    check('۷-ت: هیچ دادهٔ نمونه/قدیمی در SQL نیست', stripos($sql, 'INSERT INTO users') === false && stripos($sql, 'INSERT INTO tickets') === false);
}

/** فراخوانی بوت‌استرپ میزبان بدون بارگذاری سشن/DB. */
function food_ticket_web_host_bootstrap_standalone(string $file, string $apiBase, string $csrf, array $brand, string $role, bool $canBrowse, string $nonce, ?array $dbState): string
{
    $src = (string) file_get_contents($file);
    if (!function_exists('food_ticket_web_host_bootstrap')) {
        eval(grabFunction($file, 'food_ticket_web_host_bootstrap'));
    }
    return food_ticket_web_host_bootstrap($apiBase, $csrf, $brand, $role, $canBrowse, $nonce, $dbState);
}

echo "\n";
echo $fail === 0
    ? 'همهٔ بررسی‌های تفکیک دیتابیس خام موفق بودند (' . $pass . " مورد).\n"
    : $fail . ' مورد ناموفق از ' . ($pass + $fail) . " مورد.\n";
exit($fail === 0 ? 0 : 1);
